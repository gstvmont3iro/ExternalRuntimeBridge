<?php

namespace ExternalRuntimeBridge\Lua;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\Plugin;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\event\Event;
use pocketmine\event\Listener;
use pocketmine\event\EventPriority;
use pocketmine\event\TranslationContainer;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\math\Vector3;
use pocketmine\block\Block;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\Config;
use ExternalRuntimeBridge\Lua\LuaRuntime;
use ExternalRuntimeBridge\Lua\LuaEventListener;
use ExternalRuntimeBridge\Lua\LuaEventExecutor;
use ExternalRuntimeBridge\Lua\LuaTask;

/**
 * Native Lua plugin facade. It participates in the same Plugin lifecycle,
 * command map, scheduler and event dispatcher used by PHP plugins.
 */
class LuaPlugin extends PluginBase{

	private $runtime;
	private $externalManager;
	private $mainFile;
	private $eventListeners = [];
	private $commandCallbacks = [];
	private $timerTasks = [];
	private $activeEvent = null;
	private $activeEventStack = [];
	private $activeSender = null;
	private $bridgeToken = 0;

	public function initLua(LuaRuntime $runtime, $mainFile){
		$this->runtime = $runtime;
		$this->mainFile = $mainFile;
	}

	public function initExternalManager(\ExternalRuntimeBridge\ExternalPluginManager $manager){
		$this->externalManager = $manager;
	}

	public function onLoad(){
		try{
			$this->runtime->start();
		}catch(\Throwable $e){
			$this->logLuaError("load", $e);
			throw $e;
		}
	}

	public function onEnable(){
		try{
			if(function_exists("gc_enable")){
				gc_enable();
			}
			if(!$this->runtime->isRunning()){
				$this->runtime->start();
			}
			$this->runtime->callFunction("onEnable");
		}catch(\Throwable $e){
			$this->logLuaError("onEnable", $e);
			throw $e;
		}
	}

	public function onDisable(){
		try{
			if($this->runtime !== null and $this->runtime->isRunning()){
				$this->runtime->callFunction("onDisable");
			}
		}catch(\Throwable $e){
			$this->logLuaError("onDisable", $e);
		}finally{
			$this->cleanupLuaBindings();
			if($this->runtime !== null){
				$this->runtime->stop();
			}
		}
	}

	/**
	 * Cleans up a VM when loading failed before the plugin was enabled.
	 */
	public function cleanupAfterLoadFailure(){
		$this->cleanupLuaBindings();
		if($this->runtime !== null){
			$this->runtime->stop();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args){
		$name = strtolower($command->getName());
		if(!isset($this->commandCallbacks[$name])){
			return false;
		}
		$this->activeSender = $sender;
		try{
			$fields = [
				"sender" => $sender instanceof Player ? "player:" . $sender->getId() : "console",
				"label" => $label,
				"args" => implode("\x01", $args),
				"command" => $name,
			];
			return $this->runtime->invokeCallback($this->commandCallbacks[$name], "command", $fields) === 1;
		}catch(\Throwable $e){
			$this->logLuaError("command /" . $name, $e);
			$this->externalManager->disablePlugin($this);
			return false;
		}finally{
			$this->activeSender = null;
		}
	}

	public function registerLuaEvent($name, $callbackId, $priority = EventPriority::NORMAL, $ignoreCancelled = false){
		$class = $this->resolveEventClass($name);
		if($class === null){
			throw new \InvalidArgumentException("Unknown external event: " . $name);
		}
		$listener = new LuaEventListener($this, $name);
		$executor = new LuaEventExecutor();
		$this->getServer()->getPluginManager()->registerEvent(
			$class,
			$listener,
			(int) $priority,
			$executor,
			$this,
			(bool) $ignoreCancelled
		);
		$listener->setCallbackId($callbackId);
		$this->eventListeners[] = $listener;
	}

	public function registerLuaCommand($name, $callbackId, $description = "", $usage = "", $permission = "", $aliases = ""){
		$name = strtolower(trim($name));
		if($name === "" or strpos($name, ":") !== false){
			throw new \InvalidArgumentException("Invalid Lua command name");
		}
		$this->commandCallbacks[$name] = $callbackId;
		$command = new PluginCommand($name, $this);
		if($description !== "") $command->setDescription($description);
		if($usage !== "") $command->setUsage($usage);
		if($permission !== "") $command->setPermission($permission);
		$aliasList = [];
		foreach(explode("|", $aliases) as $alias){
			$alias = trim($alias);
			if($alias !== "" and strpos($alias, ":") === false){
				$aliasList[] = $alias;
			}
		}
		if(count($aliasList) > 0){
			$command->setAliases($aliasList);
		}
		$this->getServer()->getCommandMap()->register($this->getDescription()->getName(), $command);
	}

	public function registerLuaTimer($callbackId, $mode, $delay, $period){
		$task = new LuaTask($this, $callbackId, $this->runtime);
		if($mode === "repeat"){
			$handler = $this->getServer()->getScheduler()->scheduleRepeatingTask($task, max(1, (int) $period));
		}elseif($mode === "delay_repeat"){
			$handler = $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask($task, max(1, (int) $delay), max(1, (int) $period));
		}else{
			$handler = $this->getServer()->getScheduler()->scheduleDelayedTask($task, max(0, (int) $delay));
		}
		if($handler === null){
			throw new \RuntimeException("Could not schedule Lua task");
		}
		$this->timerTasks[$handler->getTaskId()] = $handler;
		$this->runtimeResult($handler->getTaskId());
	}

	private function runtimeResult($value){
		$this->runtime->sendResult(true, $value);
	}

	private function cleanupLuaBindings(){
		foreach($this->timerTasks as $handler){
			if($handler !== null){
				$this->getServer()->getScheduler()->cancelTask($handler->getTaskId());
			}
		}
		$this->timerTasks = [];
		$this->eventListeners = [];
		$this->commandCallbacks = [];
		$this->activeEvent = null;
		$this->activeEventStack = [];
		$this->activeSender = null;
	}

	public function onLuaEvent(Event $event, $eventName, $callbackId){
		if(!$this->isEnabled()){
			return;
		}
		$token = ++$this->bridgeToken;
		$this->activeEventStack[] = $this->activeEvent;
		$this->activeEvent = $event;
		$fields = $this->eventFields($event);
		try{
			$this->runtime->invokeCallback($callbackId, $eventName, $fields);
		}catch(\Throwable $e){
			$this->logLuaError($eventName, $e);
			$this->externalManager->disablePlugin($this);
		}finally{
			$this->activeEvent = array_pop($this->activeEventStack);
		}
	}

	public function handleLuaCall($method, array $args){
		switch($method){
			case "server.log":
				$this->getLogger()->info(isset($args[0]) ? $args[0] : "");
				return true;
			case "server.broadcast":
				$this->getServer()->broadcastMessage(isset($args[0]) ? $args[0] : "");
				return true;
			case "server.getName":
				return $this->getServer()->getName();
			case "server.getPort":
				return $this->getServer()->getPort();
			case "server.getMotd":
				return $this->getServer()->getMotd();
			case "server.getOnlinePlayerCount":
				return count($this->getServer()->getOnlinePlayers());
			case "server.getPlayer":
				$player = $this->getServer()->getPlayerExact(isset($args[0]) ? $args[0] : "");
				return $player instanceof Player ? $player->getId() : "";
			case "server.getOnlinePlayers":
				$ids = [];
				foreach($this->getServer()->getOnlinePlayers() as $player){
					$ids[] = (string) $player->getId();
				}
				return implode(",", $ids);
			case "server.getWorld":
				$world = $this->getServer()->getLevelByName(isset($args[0]) ? $args[0] : "");
				return $world !== null ? "world:" . $world->getName() : "";
			case "server.getDefaultWorld":
				$world = $this->getServer()->getDefaultLevel();
				return $world !== null ? "world:" . $world->getName() : "";
			case "server.getDataPath":
				return $this->getServer()->getDataPath();
			case "server.dispatchCommand":
				$senderId = isset($args[0]) ? (int) $args[0] : 0;
				$line = isset($args[1]) ? $args[1] : "";
				$sender = $senderId > 0 ? $this->getPlayerById($senderId) : new \pocketmine\command\ConsoleCommandSender();
				return $sender !== null ? $this->getServer()->dispatchCommand($sender, $line) : false;
			case "player.sendMessage":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$player->sendMessage(isset($args[1]) ? $args[1] : "");
				return true;
			case "player.getName":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				return $player instanceof Player ? $player->getName() : "";
			case "player.getHealth":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				return $player instanceof Player ? $player->getHealth() : 0;
			case "player.setHealth":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$player->setHealth((float) (isset($args[1]) ? $args[1] : 0));
				return true;
			case "player.getPosition":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return "0,0,0";
				$pos = $player->getPosition();
				return $pos->x . "," . $pos->y . "," . $pos->z;
			case "player.teleport":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$pos = new Vector3((float) (isset($args[1]) ? $args[1] : 0), (float) (isset($args[2]) ? $args[2] : 0), (float) (isset($args[3]) ? $args[3] : 0));
				return $player->teleport($pos);
			case "player.hasPermission":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				return $player instanceof Player ? $player->hasPermission(isset($args[1]) ? $args[1] : "") : false;
			case "sender.sendMessage":
				$sender = $this->resolveSender(isset($args[0]) ? $args[0] : "");
				if($sender === null) return false;
				$sender->sendMessage(isset($args[1]) ? $args[1] : "");
				return true;
			case "sender.getName":
				$sender = $this->resolveSender(isset($args[0]) ? $args[0] : "");
				return $sender !== null ? $sender->getName() : "";
			case "sender.hasPermission":
				$sender = $this->resolveSender(isset($args[0]) ? $args[0] : "");
				return $sender !== null ? $sender->hasPermission(isset($args[1]) ? $args[1] : "") : false;
			case "world.getName":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				return $world !== null ? $world->getName() : "";
			case "world.getPlayers":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				$ids = [];
				if($world !== null){ foreach($world->getPlayers() as $player){ $ids[] = (string) $player->getId(); } }
				return implode(",", $ids);
			case "world.getBlock":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				if($world === null) return "{}";
				$block = $world->getBlock(new Vector3((float) (isset($args[1]) ? $args[1] : 0), (float) (isset($args[2]) ? $args[2] : 0), (float) (isset($args[3]) ? $args[3] : 0)));
				return $this->serializeBlock($block);
			case "world.setBlock":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				if($world === null) return false;
				$pos = new Vector3((float) (isset($args[1]) ? $args[1] : 0), (float) (isset($args[2]) ? $args[2] : 0), (float) (isset($args[3]) ? $args[3] : 0));
				$block = Block::get((int) (isset($args[4]) ? $args[4] : 0), (int) (isset($args[5]) ? $args[5] : 0), $pos);
				return $world->setBlock($pos, $block);
			case "entity.getName":
				$entity = $this->getEntityById(isset($args[0]) ? (int) $args[0] : 0);
				return $entity !== null ? (method_exists($entity, "getName") ? (string) $entity->getName() : get_class($entity)) : "";
			case "entity.getPosition":
				$entity = $this->getEntityById(isset($args[0]) ? (int) $args[0] : 0);
				if($entity === null) return "0,0,0";
				$pos = $entity->getPosition();
				return $pos->x . "," . $pos->y . "," . $pos->z;
			case "plugin.getName":
				return $this->getName();
			case "plugin.getDataFolder":
				return $this->getDataFolder();
			case "config.get":
				$key = isset($args[0]) ? $args[0] : "";
				$value = $this->getConfig()->get($key, null);
				if($value === null) return "nil";
				if(is_bool($value)) return "b:" . ($value ? "1" : "0");
				if(is_int($value) or is_float($value)) return "n:" . $value;
				if(is_array($value)) return "j:" . json_encode($value);
				return "s:" . (string) $value;
			case "config.set":
				$key = isset($args[0]) ? $args[0] : ""; $raw = isset($args[1]) ? $args[1] : "nil";
				if(strpos($raw, "b:") === 0) $value = substr($raw, 2) === "1";
				elseif(strpos($raw, "n:") === 0) $value = (float) substr($raw, 2);
				elseif(strpos($raw, "j:") === 0){ $value = json_decode(substr($raw, 2), true); if($value === null) return false; }
				elseif(strpos($raw, "nil") === 0) $value = null;
				elseif(strpos($raw, "s:") === 0) $value = substr($raw, 2);
				else $value = $raw;
				$this->getConfig()->set($key, $value); return true;
			case "config.save":
				$this->saveConfig(); return true;
			case "config.reload":
				$this->reloadConfig(); return true;
			case "event.setCancelled":
				$value = isset($args[1]) ? ((int) $args[1] === 1) : true;
				if($this->activeEvent !== null and $this->activeEvent instanceof \pocketmine\event\Cancellable){
					$this->activeEvent->setCancelled($value);
				}
				return true;
			case "event.isCancelled":
				return $this->activeEvent !== null and $this->activeEvent instanceof \pocketmine\event\Cancellable ? $this->activeEvent->isCancelled() : false;
			case "scheduler.cancel":
				$id = isset($args[0]) ? (int) $args[0] : -1;
				$this->getServer()->getScheduler()->cancelTask($id);
				unset($this->timerTasks[$id]);
				return true;
			default:
				throw new \InvalidArgumentException("Unknown Lua bridge method: " . $method);
		}
	}

	private function resolveSender($token){
		if($token === "console") return new \pocketmine\command\ConsoleCommandSender();
		if(strpos($token, "player:") === 0) return $this->getPlayerById((int) substr($token, 7));
		return null;
	}

	private function getWorldByToken($token){
		if(strpos($token, "world:") !== 0) return null;
		return $this->getServer()->getLevelByName(substr($token, 6));
	}

	private function getEntityById($id){
		foreach($this->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $entity){
				if((int) $entity->getId() === (int) $id) return $entity;
			}
		}
		return null;
	}

	private function serializeBlock($block){
		if($block === null) return "{}";
		return json_encode([
			"id" => (int) $block->getId(),
			"damage" => (int) $block->getDamage(),
			"name" => (string) $block->getName(),
			"x" => (float) $block->x,
			"y" => (float) $block->y,
			"z" => (float) $block->z,
		]);
	}

	public function getActiveSender(){
		return $this->activeSender;
	}

	private function getPlayerById($id){
		if($id <= 0) return null;
		foreach($this->getServer()->getOnlinePlayers() as $player){
			if((int) $player->getId() === (int) $id){
				return $player;
			}
		}
		return null;
	}

	private function eventFields(Event $event){
		$fields = ["cancelled" => "0"];
		if($event instanceof \pocketmine\event\Cancellable and $event->isCancelled()){
			$fields["cancelled"] = "1";
		}
		if(method_exists($event, "getPlayer")){
			$player = $event->getPlayer();
			if($player instanceof Player){ $fields["player"] = "player:" . $player->getId(); }
		}
		if(method_exists($event, "getSender")){
			$sender = $event->getSender();
			if($sender instanceof \pocketmine\command\CommandSender){
				$fields["sender"] = $sender instanceof Player ? "player:" . $sender->getId() : "console";
			}
		}
		if(method_exists($event, "getEntity")){
			$entity = $event->getEntity();
			if($entity instanceof \pocketmine\entity\Entity){ $fields["entity"] = "entity:" . $entity->getId(); }
		}
		if(method_exists($event, "getLevel")){
			$level = $event->getLevel();
			if($level instanceof \pocketmine\level\Level){ $fields["world"] = "world:" . $level->getName(); }
		}
		if(method_exists($event, "getBlock")){
			$block = $event->getBlock();
			if($block instanceof \pocketmine\block\Block){ $fields["block"] = $this->serializeBlock($block); }
		}
		if(method_exists($event, "getMessage")){
			$message = $event->getMessage();
			if(is_string($message)) $fields["message"] = $message;
		}
		if(method_exists($event, "getJoinMessage")){
			$fields["joinMessage"] = (string) $event->getJoinMessage();
		}
		if(method_exists($event, "getQuitMessage")){
			$fields["quitMessage"] = (string) $event->getQuitMessage();
		}
		if(method_exists($event, "getReason")){
			$fields["reason"] = (string) $event->getReason();
		}
		if(method_exists($event, "getAction")){
			$fields["action"] = (string) $event->getAction();
		}
		if(method_exists($event, "getCommand")){
			$fields["command"] = (string) $event->getCommand();
		}
		return $fields;
	}

	private function resolveEventClass($name){
		$name = trim($name);
		$aliases = [
			"playerJoin" => "pocketmine\\event\\player\\PlayerJoinEvent",
			"playerQuit" => "pocketmine\\event\\player\\PlayerQuitEvent",
			"playerChat" => "pocketmine\\event\\player\\PlayerChatEvent",
			"playerInteract" => "pocketmine\\event\\player\\PlayerInteractEvent",
			"playerMove" => "pocketmine\\event\\player\\PlayerMoveEvent",
			"playerLogin" => "pocketmine\\event\\player\\PlayerLoginEvent",
			"playerKick" => "pocketmine\\event\\player\\PlayerKickEvent",
			"playerDeath" => "pocketmine\\event\\player\\PlayerDeathEvent",
			"playerCommandPreprocess" => "pocketmine\\event\\player\\PlayerCommandPreprocessEvent",
			"serverCommand" => "pocketmine\\event\\server\\ServerCommandEvent",
			"levelLoad" => "pocketmine\\event\\level\\LevelLoadEvent",
			"levelUnload" => "pocketmine\\event\\level\\LevelUnloadEvent",
			"levelSave" => "pocketmine\\event\\level\\LevelSaveEvent",
			"weatherChange" => "pocketmine\\event\\level\\WeatherChangeEvent",
			"blockBreak" => "pocketmine\\event\\block\\BlockBreakEvent",
			"blockPlace" => "pocketmine\\event\\block\\BlockPlaceEvent",
			"signChange" => "pocketmine\\event\\block\\SignChangeEvent",
			"entityDamage" => "pocketmine\\event\\entity\\EntityDamageEvent",
			"entitySpawn" => "pocketmine\\event\\entity\\EntitySpawnEvent",
		];
		if(isset($aliases[$name]) and class_exists($aliases[$name]) and is_subclass_of($aliases[$name], Event::class)){
			return $aliases[$name];
		}
		if(class_exists($name) and is_subclass_of($name, Event::class)) return $name;
		foreach(["player", "server", "level", "block", "entity", "inventory", "plugin"] as $namespace){
			$class = "pocketmine\\event\\" . $namespace . "\\" . ucfirst($name) . "Event";
			if(class_exists($class) and is_subclass_of($class, Event::class)){
				return $class;
			}
		}
		return null;
	}

	private function logLuaError($stage, \Throwable $e){
		$this->getLogger()->error("[Lua] Error in plugin " . $this->getName());
		$this->getLogger()->error("File: " . $this->mainFile);
		$this->getLogger()->error("Stage: " . $stage);
		$this->getLogger()->error("Error: " . $e->getMessage());
	}
}
