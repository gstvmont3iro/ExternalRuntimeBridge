<?php

namespace ExternalRuntimeBridge\Python;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\Plugin;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\block\Block;
use ExternalRuntimeBridge\Python\PythonEventExecutor;
use ExternalRuntimeBridge\Python\PythonEventListener;
use ExternalRuntimeBridge\Python\PythonRuntime;
use ExternalRuntimeBridge\Python\PythonTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\Config;

/**
 * Process-isolated Python plugin facade.
 *
 * Python code never receives PHP objects. Objects are represented by stable
 * opaque identifiers and all core access is routed through PythonRuntime RPC.
 */
class PythonPlugin extends PluginBase{

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

	public function initPython(PythonRuntime $runtime, $mainFile){
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
			$this->logPythonError("load", $e);
			throw $e;
		}
	}

	public function onEnable(){
		try{
			if(!$this->runtime->isRunning()){
				$this->runtime->start();
			}
			$this->runtime->callFunction("onEnable");
		}catch(\Throwable $e){
			$this->logPythonError("onEnable", $e);
			throw $e;
		}
	}

	public function onDisable(){
		try{
			if($this->runtime !== null and $this->runtime->isRunning()){
				$this->runtime->callFunction("onDisable");
			}
		}catch(\Throwable $e){
			$this->logPythonError("onDisable", $e);
		}finally{
			$this->cleanupPythonBindings();
			if($this->runtime !== null){
				$this->runtime->stop();
			}
		}
	}

	public function cleanupAfterLoadFailure(){
		$this->cleanupPythonBindings();
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
				"args" => json_encode(array_values($args)),
				"command" => $name,
			];
			$result = $this->runtime->invokeCallback($this->commandCallbacks[$name], "command", $fields);
			return $result !== 0;
		}catch(\Throwable $e){
			$this->logPythonError("command /" . $name, $e);
			$this->externalManager->disablePlugin($this);
			return false;
		}finally{
			$this->activeSender = null;
		}
	}

	public function registerPythonEvent($name, $callbackId, $priority = EventPriority::NORMAL, $ignoreCancelled = false){
		$class = $this->resolveEventClass($name);
		if($class === null){
			throw new \InvalidArgumentException("Unknown external event: " . $name);
		}
		$listener = new PythonEventListener($this, $name, (int) $callbackId);
		$executor = new PythonEventExecutor();
		$this->getServer()->getPluginManager()->registerEvent(
			$class,
			$listener,
			(int) $priority,
			$executor,
			$this,
			(bool) $ignoreCancelled
		);
		$this->eventListeners[] = $listener;
	}

	public function registerPythonCommand($name, $callbackId, $description = "", $usage = "", $permission = "", $aliases = ""){
		$name = strtolower(trim($name));
		if($name === "" or strpos($name, ":") !== false){
			throw new \InvalidArgumentException("Invalid Python command name");
		}
		$this->commandCallbacks[$name] = (int) $callbackId;

		// A manifest-declared command may have already been created by the
		// regular PluginManager. Reuse it when it belongs to this plugin.
		$command = $this->getServer()->getPluginCommand($name);
		if(!($command instanceof PluginCommand) or $command->getPlugin() !== $this){
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
		}else{
			if($description !== "") $command->setDescription($description);
			if($usage !== "") $command->setUsage($usage);
			if($permission !== "") $command->setPermission($permission);
		}
		return $command->getLabel();
	}

	public function registerPythonTimer($callbackId, $mode, $delay, $period){
		$task = new PythonTask($this, $callbackId, $this->runtime);
		if($mode === "repeat"){
			$handler = $this->getServer()->getScheduler()->scheduleRepeatingTask($task, max(1, (int) $period));
		}elseif($mode === "delay_repeat"){
			$handler = $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask($task, max(1, (int) $delay), max(1, (int) $period));
		}else{
			$handler = $this->getServer()->getScheduler()->scheduleDelayedTask($task, max(0, (int) $delay));
		}
		if(!($handler instanceof TaskHandler)){
			throw new \RuntimeException("Could not schedule Python task");
		}
		$this->timerTasks[$handler->getTaskId()] = $handler;
		$this->runtime->sendResult(true, $handler->getTaskId());
	}

	private function cleanupPythonBindings(){
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

	public function onPythonEvent(Event $event, $eventName, $callbackId){
		if(!$this->isEnabled()){
			return;
		}
		$this->activeEventStack[] = $this->activeEvent;
		$this->activeEvent = $event;
		try{
			$this->runtime->invokeCallback($callbackId, $eventName, $this->eventFields($event));
		}catch(\Throwable $e){
			$this->logPythonError($eventName, $e);
			$this->externalManager->disablePlugin($this);
		}finally{
			$this->activeEvent = array_pop($this->activeEventStack);
		}
	}

	public function handlePythonCall($method, array $args){
		switch($method){
			case "server.log":
				$this->getLogger()->info(isset($args[0]) ? $args[0] : "");
				return true;

			case "server.broadcast":
				return $this->getServer()->broadcastMessage(isset($args[0]) ? $args[0] : "") >= 0;

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
				return $player instanceof Player ? "player:" . $player->getId() : "";

			case "server.getOnlinePlayers":
				$ids = [];
				foreach($this->getServer()->getOnlinePlayers() as $player){
					$ids[] = (int) $player->getId();
				}
				return json_encode($ids);

			case "server.getWorld":
				$name = isset($args[0]) ? $args[0] : "";
				$world = $this->getServer()->getLevelByName($name);
				return $world !== null ? "world:" . $world->getName() : "";

			case "server.getDefaultWorld":
				$world = $this->getServer()->getDefaultLevel();
				return $world !== null ? "world:" . $world->getName() : "";

			case "server.dispatchCommand":
				$senderId = isset($args[0]) ? (int) $args[0] : 0;
				$line = isset($args[1]) ? $args[1] : "";
				$sender = $senderId > 0 ? $this->getPlayerById($senderId) : new \pocketmine\command\ConsoleCommandSender();
				return $sender !== null ? $this->getServer()->dispatchCommand($sender, $line) : false;

			case "server.getDataPath":
				return $this->getServer()->getDataPath();

			case "plugin.getName":
				return $this->getName();

			case "plugin.getDataFolder":
				return $this->getDataFolder();

			case "player.sendMessage":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$player->sendMessage(isset($args[1]) ? $args[1] : "");
				return true;

			case "player.getName":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				return $player instanceof Player ? $player->getName() : "";

			case "player.getPosition":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return json_encode([0, 0, 0]);
				$pos = $player->getPosition();
				return json_encode([(float) $pos->x, (float) $pos->y, (float) $pos->z]);

			case "player.getHealth":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				return $player instanceof Player ? (float) $player->getHealth() : 0;

			case "player.setHealth":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$player->setHealth((float) (isset($args[1]) ? $args[1] : 0));
				return true;

			case "player.teleport":
				$player = $this->getPlayerById(isset($args[0]) ? (int) $args[0] : 0);
				if(!$player instanceof Player) return false;
				$pos = new Vector3(
					(float) (isset($args[1]) ? $args[1] : 0),
					(float) (isset($args[2]) ? $args[2] : 0),
					(float) (isset($args[3]) ? $args[3] : 0)
				);
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
				if($world !== null){
					foreach($world->getPlayers() as $player){
						$ids[] = (int) $player->getId();
					}
				}
				return json_encode($ids);

			case "world.getBlock":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				if($world === null) return json_encode([]);
				$block = $world->getBlock(new Vector3(
					(float) (isset($args[1]) ? $args[1] : 0),
					(float) (isset($args[2]) ? $args[2] : 0),
					(float) (isset($args[3]) ? $args[3] : 0)
				));
				return $this->serializeBlock($block);

			case "world.setBlock":
				$world = $this->getWorldByToken(isset($args[0]) ? $args[0] : "");
				if($world === null) return false;
				$pos = new Vector3(
					(float) (isset($args[1]) ? $args[1] : 0),
					(float) (isset($args[2]) ? $args[2] : 0),
					(float) (isset($args[3]) ? $args[3] : 0)
				);
				$block = Block::get(
					(int) (isset($args[4]) ? $args[4] : 0),
					(int) (isset($args[5]) ? $args[5] : 0),
					$pos
				);
				return $world->setBlock($pos, $block);

			case "entity.getPosition":
				$entity = $this->getEntityById(isset($args[0]) ? (int) $args[0] : 0);
				if($entity === null) return json_encode([0, 0, 0]);
				$pos = $entity->getPosition();
				return json_encode([(float) $pos->x, (float) $pos->y, (float) $pos->z]);

			case "entity.getName":
				$entity = $this->getEntityById(isset($args[0]) ? (int) $args[0] : 0);
				return $entity !== null ? (method_exists($entity, "getName") ? (string) $entity->getName() : get_class($entity)) : "";

			case "config.get":
				$key = isset($args[0]) ? $args[0] : "";
				$value = $this->getConfig()->get($key, null);
				return $this->encodeConfigValue($value);

			case "config.set":
				$key = isset($args[0]) ? $args[0] : "";
				$this->getConfig()->set($key, isset($args[1]) ? $this->decodeConfigValue($args[1]) : null);
				return true;

			case "config.save":
				$this->saveConfig();
				return true;

			case "config.reload":
				$this->reloadConfig();
				return true;

			case "event.setCancelled":
				$value = isset($args[1]) ? ((int) $args[1] === 1) : true;
				if($this->activeEvent !== null and $this->activeEvent instanceof \pocketmine\event\Cancellable){
					$this->activeEvent->setCancelled($value);
				}
				return true;

			case "event.isCancelled":
				return $this->activeEvent !== null and $this->activeEvent instanceof \pocketmine\event\Cancellable
					? $this->activeEvent->isCancelled()
					: false;

			case "scheduler.cancel":
				$id = isset($args[0]) ? (int) $args[0] : -1;
				$this->getServer()->getScheduler()->cancelTask($id);
				unset($this->timerTasks[$id]);
				return true;

			default:
				throw new \InvalidArgumentException("Unknown Python bridge method: " . $method);
		}
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

	private function resolveSender($token){
		if($token === "console"){
			return new \pocketmine\command\ConsoleCommandSender();
		}
		if(strpos($token, "player:") === 0){
			return $this->getPlayerById((int) substr($token, 7));
		}
		return null;
	}

	private function getWorldByToken($token){
		if(strpos($token, "world:") !== 0) return null;
		return $this->getServer()->getLevelByName(substr($token, 6));
	}

	private function getEntityById($id){
		foreach($this->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $entity){
				if((int) $entity->getId() === (int) $id){
					return $entity;
				}
			}
		}
		return null;
	}

	private function serializeBlock($block){
		if($block === null) return json_encode([]);
		return json_encode([
			"id" => (int) $block->getId(),
			"damage" => (int) $block->getDamage(),
			"name" => (string) $block->getName(),
			"x" => (float) $block->x,
			"y" => (float) $block->y,
			"z" => (float) $block->z,
		]);
	}

	private function encodeConfigValue($value){
		if($value === null) return "null:";
		if(is_bool($value)) return "bool:" . ($value ? "1" : "0");
		if(is_int($value)) return "int:" . $value;
		if(is_float($value)) return "float:" . $value;
		if(is_array($value)) return "json:" . json_encode($value);
		return "string:" . (string) $value;
	}

	private function decodeConfigValue($value){
		if(strpos($value, "bool:") === 0) return substr($value, 5) === "1";
		if(strpos($value, "int:") === 0) return (int) substr($value, 4);
		if(strpos($value, "float:") === 0) return (float) substr($value, 6);
		if(strpos($value, "json:") === 0){
			$v = json_decode(substr($value, 5), true);
			return $v === null ? [] : $v;
		}
		if(strpos($value, "null:") === 0) return null;
		if(strpos($value, "string:") === 0) return substr($value, 7);
		return $value;
	}

	private function eventFields(Event $event){
		$fields = ["cancelled" => $event instanceof \pocketmine\event\Cancellable and $event->isCancelled() ? "1" : "0"];

		if(method_exists($event, "getPlayer")){
			$player = $event->getPlayer();
			if($player instanceof Player){
				$fields["player"] = "player:" . $player->getId();
			}
		}
		if(method_exists($event, "getSender")){
			$sender = $event->getSender();
			if($sender instanceof \pocketmine\command\CommandSender){
				$fields["sender"] = $sender instanceof Player ? "player:" . $sender->getId() : "console";
			}
		}
		if(method_exists($event, "getEntity")){
			$entity = $event->getEntity();
			if($entity instanceof \pocketmine\entity\Entity){
				$fields["entity"] = "entity:" . $entity->getId();
			}
		}
		if(method_exists($event, "getLevel")){
			$level = $event->getLevel();
			if($level instanceof \pocketmine\level\Level){
				$fields["world"] = "world:" . $level->getName();
			}
		}
		if(method_exists($event, "getBlock")){
			$block = $event->getBlock();
			if($block instanceof Block){
				$fields["block"] = $this->serializeBlock($block);
			}
		}
		$scalarMethods = [
			"getMessage" => "message",
			"getJoinMessage" => "joinMessage",
			"getQuitMessage" => "quitMessage",
			"getReason" => "reason",
			"getAction" => "action",
			"getCommand" => "command",
		];
		foreach($scalarMethods as $method => $key){
			if(method_exists($event, $method)){
				$value = $event->{$method}();
				if(is_scalar($value) or $value === null){
					$fields[$key] = (string) $value;
				}
			}
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
			"entityDeath" => "pocketmine\\event\\entity\\EntityDeathEvent",
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

	private function logPythonError($stage, \Throwable $e){
		$this->getLogger()->error("[PythonPlugin] Erro no plugin " . $this->getName());
		$this->getLogger()->error("Arquivo: " . $this->mainFile);
		$this->getLogger()->error("Etapa: " . $stage);
		$this->getLogger()->error("Erro: " . $e->getMessage());
	}
}
