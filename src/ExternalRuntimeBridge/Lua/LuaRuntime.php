<?php

namespace ExternalRuntimeBridge\Lua;

use ExternalRuntimeBridge\Lua\LuaPlugin;

/**
 * Controls one persistent native Lua 5.4 state and translates its RPC
 * messages into calls against the server core.
 *
 * The runtime is deliberately process-isolated from PHP. Lua scripts never
 * receive PHP object references, filesystem handles or executable callbacks.
 */
class LuaRuntime{

	private $plugin;
	private $mainFile;
	private $process = null;
	private $stdin = null;
	private $stdout = null;
	private $stderr = null;
	private $executable;
	private $timeoutMs;
	private $stdoutBuffer = "";

	public function __construct(LuaPlugin $plugin, $mainFile, $executable){
		$this->plugin = $plugin;
		$this->mainFile = $mainFile;
		$this->executable = $executable;
		$timeout = getenv("EXTERNAL_LUA_TIMEOUT_MS");
		$this->timeoutMs = $timeout === false ? 1000 : max(50, min(60000, (int) $timeout));
	}

	public function isRunning(){
		if(!is_resource($this->process)){
			return false;
		}
		$status = @proc_get_status($this->process);
		return is_array($status) and isset($status["running"]) and $status["running"] === true;
	}

	public function start(){
		if($this->isRunning()){
			return true;
		}
		if(!is_file($this->executable) or !is_executable($this->executable)){
			throw new \RuntimeException("Lua runtime executable not found or not executable: " . $this->executable);
		}
		if(!is_file($this->mainFile)){
			throw new \RuntimeException("Lua main file not found: " . $this->mainFile);
		}

		$descriptor = [
			0 => ["pipe", "r"],
			1 => ["pipe", "w"],
			2 => ["pipe", "w"],
		];
		$cmd = escapeshellarg($this->executable) . " " . escapeshellarg($this->mainFile);
		$this->process = proc_open($cmd, $descriptor, $pipes, dirname($this->mainFile));
		if(!is_resource($this->process)){
			$this->process = null;
			throw new \RuntimeException("Could not start the native Lua runtime");
		}
		$this->stdin = $pipes[0];
		$this->stdout = $pipes[1];
		$this->stderr = $pipes[2];
		$this->stdoutBuffer = "";
		@stream_set_blocking($this->stdout, false);
		@stream_set_blocking($this->stderr, false);

		$this->readUntil(["READY"]);
		return true;
	}

	public function stop(){
		if(!is_resource($this->process)){
			$this->stdin = $this->stdout = $this->stderr = null;
			return;
		}
		if($this->isRunning() and is_resource($this->stdin)){
			try{
				$this->send(["SHUTDOWN"]);
			}catch(\Throwable $e){
				// The child may already have exited; cleanup below still reaps it.
			}
		}
		if(is_resource($this->stdin)) @fclose($this->stdin);
		if(is_resource($this->stdout)) @fclose($this->stdout);
		if(is_resource($this->stderr)) @fclose($this->stderr);
		@proc_close($this->process);
		$this->stdin = $this->stdout = $this->stderr = null;
		$this->process = null;
		$this->stdoutBuffer = "";
	}

	public function callFunction($function){
		$this->send(["CALL_FUNCTION", $function]);
		$result = $this->readUntil(["FUNCTION_RETURN", "CALLBACK_ERROR"]);
		if($result[0] === "CALLBACK_ERROR"){
			throw new \RuntimeException(trim(($result[1] !== "" ? $result[1] . "\n" : "") . $result[2]));
		}
		return $result[2] === "1";
	}

	public function invokeCallback($callbackId, $eventName, array $fields){
		$args = ["INVOKE", (string) $callbackId, rawurlencode($eventName)];
		foreach($fields as $key => $value){
			$args[] = rawurlencode((string) $key);
			$args[] = rawurlencode((string) $value);
		}
		$this->sendRaw($args);
		$result = $this->readUntil(["RETURN", "CALLBACK_ERROR"]);
		if($result[0] === "CALLBACK_ERROR"){
			throw new \RuntimeException(trim(($result[1] !== "" ? $result[1] . "\n" : "") . $result[2]));
		}
		return isset($result[2]) ? (int) $result[2] : 1;
	}

	private function send(array $parts){
		$encoded = ["CALL_FUNCTION" === $parts[0] ? $parts[0] : $parts[0]];
		for($i = 1; $i < count($parts); ++$i){
			$encoded[] = rawurlencode((string) $parts[$i]);
		}
		$this->sendRaw($encoded);
	}

	private function sendRaw(array $parts){
		if(!is_resource($this->stdin)){
			throw new \RuntimeException("Lua runtime is not connected");
		}
		$line = implode("\t", $parts) . "\n";
		if(@fwrite($this->stdin, $line) === false){
			throw new \RuntimeException("Unable to send data to the Lua runtime");
		}
		@fflush($this->stdin);
	}

	private function readUntil(array $expected){
		$deadline = microtime(true) + ($this->timeoutMs / 1000.0);
		while(true){
			$line = $this->readLine($deadline);
			$parts = explode("\t", trim($line));
			$tag = array_shift($parts);
			$parts = array_map("rawurldecode", $parts);

			switch($tag){
				case "CALL":
					$this->handleCall($parts);
					continue 2;
				case "REGISTER_EVENT":
					$this->plugin->registerLuaEvent(
						isset($parts[0]) ? $parts[0] : "",
						isset($parts[1]) ? (int) $parts[1] : 0,
						isset($parts[2]) ? (int) $parts[2] : 3,
						isset($parts[3]) ? ((int) $parts[3] === 1) : false
					);
					continue 2;
				case "REGISTER_COMMAND":
					$this->plugin->registerLuaCommand(
						isset($parts[0]) ? $parts[0] : "",
						isset($parts[1]) ? (int) $parts[1] : 0,
						isset($parts[2]) ? $parts[2] : "",
						isset($parts[3]) ? $parts[3] : "",
						isset($parts[4]) ? $parts[4] : "",
						isset($parts[5]) ? $parts[5] : ""
					);
					continue 2;
				case "REGISTER_TIMER":
					$this->plugin->registerLuaTimer(
						isset($parts[0]) ? (int) $parts[0] : 0,
						isset($parts[1]) ? $parts[1] : "later",
						isset($parts[2]) ? (int) $parts[2] : -1,
						isset($parts[3]) ? (int) $parts[3] : -1
					);
					continue 2;
				case "READY":
					if(in_array("READY", $expected, true)){
						return [$tag, null, null];
					}
					continue 2;
				case "RETURN":
				case "CALLBACK_ERROR":
				case "FUNCTION_RETURN":
					if(in_array($tag, $expected, true)){
						return [$tag, isset($parts[0]) ? $parts[0] : "", isset($parts[1]) ? $parts[1] : ""];
					}
					continue 2;
				case "ERROR":
					$msg = isset($parts[1]) ? $parts[1] : (isset($parts[0]) ? $parts[0] : "Lua error");
					throw new \RuntimeException($msg);
				default:
					continue 2;
			}
		}
	}

	private function readLine($deadline){
		while(true){
			$pos = strpos($this->stdoutBuffer, "\n");
			if($pos !== false){
				$line = substr($this->stdoutBuffer, 0, $pos);
				$this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);
				return $line;
			}
			if(!is_resource($this->stdout)) throw new \RuntimeException("Lua runtime stdout unavailable");
			$remaining = $deadline - microtime(true);
			if($remaining <= 0) throw new \RuntimeException("Lua runtime timeout after " . $this->timeoutMs . " ms");
			$seconds = (int) floor($remaining);
			$usec = (int) (($remaining - $seconds) * 1000000);
			$read = [$this->stdout]; $write = null; $except = null;
			$ready = @stream_select($read, $write, $except, $seconds, $usec);
			if($ready === false) throw new \RuntimeException("Unable to wait for Lua runtime");
			if($ready === 0) throw new \RuntimeException("Lua runtime timeout after " . $this->timeoutMs . " ms");
			$chunk = @fread($this->stdout, 8192);
			if($chunk === false or $chunk === ""){
				if(!$this->isRunning()){
					$error = $this->readStderr();
					throw new \RuntimeException("Lua runtime disconnected" . ($error !== "" ? ": " . $error : ""));
				}
				continue;
			}
			$this->stdoutBuffer .= $chunk;
		}
	}

	private function handleCall(array $parts){
		$method = isset($parts[0]) ? $parts[0] : "";
		$args = array_slice($parts, 1);
		try{
			$result = $this->plugin->handleLuaCall($method, $args);
			$this->sendResult(true, $result);
		}catch(\Throwable $e){
			$this->sendResult(false, $e->getMessage());
			throw $e;
		}
	}

	public function sendResult($ok, $value){
		if(is_bool($value)){
			$value = $value ? "1" : "0";
		}elseif(is_int($value) or is_float($value)){
			$value = (string) $value;
		}elseif($value === null){
			$value = "";
		}else{
			$value = (string) $value;
		}
		$this->sendRaw(["RESULT", $ok ? "1" : "0", rawurlencode($value)]);
	}

	private function readStderr(){
		if(!is_resource($this->stderr)){
			return "";
		}
		$out = "";
		while(($line = fgets($this->stderr)) !== false){
			$out .= trim($line) . "\n";
			if(strlen($out) > 4096){
				break;
			}
		}
		return trim($out);
	}

}
