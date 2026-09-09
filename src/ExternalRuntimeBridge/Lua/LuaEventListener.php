<?php
namespace ExternalRuntimeBridge\Lua;
use pocketmine\event\Listener;
use pocketmine\event\Event;
use ExternalRuntimeBridge\Lua\LuaPlugin;

class LuaEventListener implements Listener{
	private $plugin;
	private $eventName;
	private $callbackId = 0;

	public function __construct(LuaPlugin $plugin, $eventName){
		$this->plugin = $plugin;
		$this->eventName = $eventName;
	}
	public function setCallbackId($callbackId){
		$this->callbackId = (int) $callbackId;
	}
	public function getPlugin(){
		return $this->plugin;
	}
	public function handle(Event $event){
		$this->plugin->onLuaEvent($event, $this->eventName, $this->callbackId);
	}
}
