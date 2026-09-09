<?php
namespace ExternalRuntimeBridge\Python;

use ExternalRuntimeBridge\Python\PythonPlugin;
use pocketmine\scheduler\PluginTask;

class PythonTask extends PluginTask{
	private $callbackId;
	private $runtime;

	public function __construct(PythonPlugin $owner, $callbackId, PythonRuntime $runtime){
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
			$this->getOwner()->getLogger()->error("[PythonPlugin] Scheduler callback failed: " . $e->getMessage());
			$this->getOwner()->getServer()->getPluginManager()->disablePlugin($this->getOwner());
		}
	}
}
