<?php
namespace ExternalRuntimeBridge\Lua;
use ExternalRuntimeBridge\Lua\LuaPlugin;
use pocketmine\scheduler\PluginTask;

class LuaTask extends PluginTask{
	private $callbackId;
	private $runtime;

	public function __construct(LuaPlugin $owner, $callbackId, LuaRuntime $runtime){
		parent::__construct($owner);
		$this->callbackId = (int) $callbackId;
		$this->runtime = $runtime;
	}
	public function onRun($currentTick){
		if(!$this->getOwner()->isEnabled() or !$this->runtime->isRunning()){
			return;
		}
		try{
			$this->runtime->invokeCallback($this->callbackId, "timer", ["tick" => (string) $currentTick]);
		}catch(\Throwable $e){
			$this->getOwner()->getLogger()->error("[Lua] Scheduler callback failed: " . $e->getMessage());
			$this->getOwner()->getServer()->getPluginManager()->disablePlugin($this->getOwner());
		}
	}
}
