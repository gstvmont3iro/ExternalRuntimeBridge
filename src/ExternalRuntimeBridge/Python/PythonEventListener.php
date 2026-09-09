<?php
namespace ExternalRuntimeBridge\Python;

use pocketmine\event\Event;
use pocketmine\event\Listener;
use ExternalRuntimeBridge\Python\PythonPlugin;

class PythonEventListener implements Listener{
	private $plugin;
	private $eventName;
	private $callbackId;

	public function __construct(PythonPlugin $plugin, $eventName, $callbackId){
		$this->plugin = $plugin;
		$this->eventName = $eventName;
		$this->callbackId = (int) $callbackId;
	}

	public function getPlugin(){
		return $this->plugin;
	}

	public function handle(Event $event){
		$this->plugin->onPythonEvent($event, $this->eventName, $this->callbackId);
	}
}
