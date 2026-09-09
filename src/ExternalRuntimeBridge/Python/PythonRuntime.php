<?php
namespace ExternalRuntimeBridge\Python;

use ExternalRuntimeBridge\Python\PythonPlugin;

/**
 * Persistent process controller for one Python plugin.
 *
 * Each plugin gets an isolated localhost TCP connection. The socket is bound
 * to 127.0.0.1 on an ephemeral port and protected with a random per-process
 * token. Every operation has a bounded read timeout.
 */
class PythonRuntime{
	private $plugin;
	private $mainFile;
	private $process = null;
	private $serverSocket = null;
	private $socket = null;
	private $stderr = null;
	private $executable;
	private $timeoutMs;
	private $socketBuffer = "";
	private $token;
	private $runtimeScript;

	public function __construct(PythonPlugin $plugin, $mainFile, $runtimeScript, $executable = null){
		$this->plugin = $plugin;
		$this->mainFile = $mainFile;
		$this->runtimeScript = $runtimeScript;
		$this->executable = $executable !== null ? $executable : $this->resolveExecutable();
		$timeout = getenv("EXTERNAL_PYTHON_TIMEOUT_MS");
		$this->timeoutMs = $timeout === false ? 1000 : max(50, (int) $timeout);
		$this->token = bin2hex(random_bytes(16));
	}

	private function resolveExecutable(){
		$candidates = [];
		$env = getenv("EXTERNAL_PYTHON");
		if($env !== false and trim($env) !== ""){
			$candidates[] = trim($env);
		}
		$candidates[] = "python3";
		$candidates[] = "python";

		foreach($candidates as $candidate){
			if((strpos($candidate, DIRECTORY_SEPARATOR) !== false and is_file($candidate) and is_executable($candidate)) or
				(strpos($candidate, DIRECTORY_SEPARATOR) === false and $this->commandExists($candidate))){
				return $candidate;
			}
		}
		return null;
	}

	private function commandExists($command){
		$out = [];
		$code = 1;
		@exec("command -v " . escapeshellarg($command) . " 2>/dev/null", $out, $code);
		return $code === 0 and isset($out[0]) and trim($out[0]) !== "";
	}

	public function getExecutable(){
		return $this->executable;
	}

	public function isRunning(){
		if(!is_resource($this->process)){
			return false;
		}
		$status = @proc_get_status($this->process);
		return is_array($status) and !empty($status["running"]);
	}

	public function start(){
		if($this->isRunning()){
			return true;
		}
		if($this->executable === null){
			throw new \RuntimeException("Python 3 runtime not found. Configure EXTERNAL_PYTHON or install python3.");
		}
		if(!is_file($this->mainFile)){
			throw new \RuntimeException("Python plugin main file not found: " . $this->mainFile);
		}
		if(!is_file($this->runtimeScript)){
			throw new \RuntimeException("Python runtime helper not found: " . $this->runtimeScript);
		}

		$this->serverSocket = @stream_socket_server(
			"tcp://127.0.0.1:0",
			$errno,
			$errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
		);
		if(!is_resource($this->serverSocket)){
			throw new \RuntimeException("Could not create Python bridge socket: " . $errstr . " (" . $errno . ")");
		}
		@stream_set_blocking($this->serverSocket, false);

		$address = @stream_socket_get_name($this->serverSocket, false);
		$port = $this->extractPort($address);
		if($port <= 0){
			$this->closeSocketResources();
			throw new \RuntimeException("Could not determine the Python bridge socket port");
		}

		$descriptor = [
			0 => ["file", DIRECTORY_SEPARATOR === "\\" ? "NUL" : "/dev/null", "r"],
			1 => ["file", DIRECTORY_SEPARATOR === "\\" ? "NUL" : "/dev/null", "w"],
			2 => ["pipe", "w"],
		];

		$cmd = escapeshellarg($this->executable) . " " .
			escapeshellarg($this->runtimeScript) . " " .
			escapeshellarg("127.0.0.1") . " " .
			escapeshellarg((string) $port) . " " .
			escapeshellarg($this->token) . " " .
			escapeshellarg($this->mainFile);

		$this->process = @proc_open($cmd, $descriptor, $pipes, dirname($this->mainFile));
		if(!is_resource($this->process)){
			$this->closeSocketResources();
			throw new \RuntimeException("Could not start Python runtime process");
		}
		$this->stderr = $pipes[2];
		@stream_set_blocking($this->stderr, false);

		try{
			$this->acceptClient();
			$this->readUntil(["READY"]);
			return true;
		}catch(\Throwable $e){
			$this->stop();
			throw $e;
		}
	}

	private function acceptClient(){
		$deadline = microtime(true) + ($this->timeoutMs / 1000.0);
		while($this->socket === null){
			$this->drainStderr();
			if(!$this->isRunning()){
				throw new \RuntimeException("Python runtime exited before connecting" . ($this->drainStderr() !== "" ? ": " . $this->drainStderr() : ""));
			}
			$remaining = $deadline - microtime(true);
			if($remaining <= 0){
				throw new \RuntimeException("Python runtime connection timeout after " . $this->timeoutMs . " ms");
			}
			$read = [$this->serverSocket];
			$write = null;
			$except = null;
			$seconds = (int) floor($remaining);
			$usec = (int) (($remaining - $seconds) * 1000000);
			$ready = @stream_select($read, $write, $except, $seconds, $usec);
			if($ready === false){
				throw new \RuntimeException("Unable to accept Python runtime connection");
			}
			if($ready === 0){
				throw new \RuntimeException("Python runtime connection timeout after " . $this->timeoutMs . " ms");
			}
			$client = @stream_socket_accept($this->serverSocket, 0);
			if(is_resource($client)){
				@stream_set_blocking($client, false);
				$this->socket = $client;
			}
		}
		@fclose($this->serverSocket);
		$this->serverSocket = null;

		// Authenticate the process before allowing any bridge request.
		$line = $this->readLine(microtime(true) + ($this->timeoutMs / 1000.0));
		$parts = explode("\t", trim($line));
		$tag = array_shift($parts);
		$values = array_map("rawurldecode", $parts);
		if($tag !== "HELLO" or !isset($values[0]) or !hash_equals($this->token, $values[0])){
			throw new \RuntimeException("Invalid Python bridge authentication");
		}
		$this->send(["HELLO_OK"]);
	}

	private function extractPort($address){
		if(!is_string($address)) return 0;
		$pos = strrpos($address, ":");
		return $pos === false ? 0 : (int) substr($address, $pos + 1);
	}

	public function stop(){
		if(is_resource($this->socket) and $this->isRunning()){
			try{
				$this->send(["SHUTDOWN"]);
				$deadline = microtime(true) + 0.25;
				while($this->isRunning() and microtime(true) < $deadline){
					$this->drainStderr();
					usleep(10000);
				}
			}catch(\Throwable $e){
				// Cleanup below still terminates and closes all resources.
			}
		}
		if($this->isRunning()){
			@proc_terminate($this->process, 15);
			$deadline = microtime(true) + 0.5;
			while($this->isRunning() and microtime(true) < $deadline){
				usleep(10000);
			}
			if($this->isRunning()){
				@proc_terminate($this->process, 9);
			}
		}

		$this->closeSocketResources();
		if(is_resource($this->process)){
			@proc_close($this->process);
		}
		$this->process = null;
		$this->socketBuffer = "";
	}

	private function closeSocketResources(){
		if(is_resource($this->socket)) @fclose($this->socket);
		if(is_resource($this->serverSocket)) @fclose($this->serverSocket);
		if(is_resource($this->stderr)) @fclose($this->stderr);
		$this->socket = $this->serverSocket = $this->stderr = null;
	}

	public function callFunction($function){
		$this->send(["CALL_FUNCTION", $function]);
		$result = $this->readUntil(["FUNCTION_RETURN", "CALLBACK_ERROR"]);
		if($result[0] === "CALLBACK_ERROR"){
			throw new \RuntimeException($result[1] . ($result[2] !== "" ? "\n" . $result[2] : ""));
		}
		return isset($result[2]) ? (int) $result[2] !== 0 : false;
	}

	public function invokeCallback($callbackId, $eventName, array $fields){
		$args = ["INVOKE", (string) $callbackId, $eventName];
		foreach($fields as $key => $value){
			$args[] = (string) $key;
			$args[] = (string) $value;
		}
		$this->send($args);
		$result = $this->readUntil(["RETURN", "CALLBACK_ERROR"]);
		if($result[0] === "CALLBACK_ERROR"){
			throw new \RuntimeException($result[1] . ($result[2] !== "" ? "\n" . $result[2] : ""));
		}
		return isset($result[2]) ? (int) $result[2] : 1;
	}

	private function send(array $parts){
		$encoded = [];
		foreach($parts as $index => $part){
			$encoded[] = $index === 0 ? (string) $part : rawurlencode((string) $part);
		}
		$this->sendRaw($encoded);
	}

	private function sendRaw(array $parts){
		if(!is_resource($this->socket)){
			throw new \RuntimeException("Python runtime socket is not connected");
		}
		$line = implode("\t", $parts) . "\n";
		$length = strlen($line);
		$written = 0;
		while($written < $length){
			$chunk = @fwrite($this->socket, substr($line, $written));
			if($chunk === false or $chunk === 0){
				throw new \RuntimeException("Unable to send data to the Python runtime");
			}
			$written += $chunk;
		}
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
					$this->plugin->registerPythonEvent(
						isset($parts[0]) ? $parts[0] : "",
						isset($parts[1]) ? (int) $parts[1] : 0,
						isset($parts[2]) ? (int) $parts[2] : 3,
						isset($parts[3]) ? ((int) $parts[3] === 1) : false
					);
					continue 2;

				case "REGISTER_COMMAND":
					$this->plugin->registerPythonCommand(
						isset($parts[0]) ? $parts[0] : "",
						isset($parts[1]) ? (int) $parts[1] : 0,
						isset($parts[2]) ? $parts[2] : "",
						isset($parts[3]) ? $parts[3] : "",
						isset($parts[4]) ? $parts[4] : "",
						isset($parts[5]) ? $parts[5] : ""
					);
					continue 2;

				case "REGISTER_TIMER":
					$this->plugin->registerPythonTimer(
						isset($parts[0]) ? (int) $parts[0] : 0,
						isset($parts[1]) ? $parts[1] : "delay",
						isset($parts[2]) ? (int) $parts[2] : 0,
						isset($parts[3]) ? (int) $parts[3] : -1
					);
					continue 2;

				case "HELLO_OK":
					continue 2;

				case "READY":
					if(in_array("READY", $expected, true)){
						return [$tag, null, null];
					}
					continue 2;

				case "RETURN":
				case "CALLBACK_ERROR":
				case "FUNCTION_RETURN":
				case "RESULT":
					if(in_array($tag, $expected, true)){
						return [$tag, isset($parts[0]) ? $parts[0] : "", isset($parts[1]) ? $parts[1] : ""];
					}
					continue 2;

				case "ERROR":
					$msg = isset($parts[1]) ? $parts[1] : (isset($parts[0]) ? $parts[0] : "Python error");
					throw new \RuntimeException($msg);

				default:
					continue 2;
			}
		}
	}

	private function readLine($deadline){
		while(true){
			$pos = strpos($this->socketBuffer, "\n");
			if($pos !== false){
				$line = substr($this->socketBuffer, 0, $pos);
				$this->socketBuffer = substr($this->socketBuffer, $pos + 1);
				return $line;
			}

			$this->drainStderr();
			if(!is_resource($this->socket)){
				throw new \RuntimeException("Python runtime socket is unavailable");
			}
			$remaining = $deadline - microtime(true);
			if($remaining <= 0){
				throw new \RuntimeException("Python runtime timeout after " . $this->timeoutMs . " ms");
			}

			$seconds = (int) floor($remaining);
			$usec = (int) (($remaining - $seconds) * 1000000);
			$read = [$this->socket];
			$write = null;
			$except = null;
			$ready = @stream_select($read, $write, $except, $seconds, $usec);
			if($ready === false){
				throw new \RuntimeException("Unable to wait for Python runtime");
			}
			if($ready === 0){
				throw new \RuntimeException("Python runtime timeout after " . $this->timeoutMs . " ms");
			}
			$chunk = @fread($this->socket, 8192);
			if($chunk === false or $chunk === ""){
				if(!$this->isRunning()){
					$error = trim($this->drainStderr());
					throw new \RuntimeException("Python runtime disconnected" . ($error !== "" ? ": " . $error : ""));
				}
				continue;
			}
			$this->socketBuffer .= $chunk;
		}
	}

	private function handleCall(array $parts){
		$method = isset($parts[0]) ? $parts[0] : "";
		$args = array_slice($parts, 1);
		try{
			$result = $this->plugin->handlePythonCall($method, $args);
			$this->sendResult(true, $result);
		}catch(\Throwable $e){
			$this->sendResult(false, $e->getMessage());
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
		$this->send(["RESULT", $ok ? "1" : "0", $value]);
	}

	private function drainStderr(){
		if(!is_resource($this->stderr)) return "";
		$data = "";
		while(true){
			$chunk = @fread($this->stderr, 8192);
			if($chunk === false or $chunk === ""){
				break;
			}
			$data .= $chunk;
			if(strlen($data) > 8192){
				break;
			}
		}
		if($data !== ""){
			$lines = preg_split("/\r?\n/", trim($data));
			foreach($lines as $line){
				if(trim($line) !== ""){
					$this->plugin->getLogger()->debug("[PythonPlugin] " . trim($line));
				}
			}
		}
		return $data;
	}
}
