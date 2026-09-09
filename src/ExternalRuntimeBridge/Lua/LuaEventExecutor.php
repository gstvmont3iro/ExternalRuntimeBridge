<?php
namespace ExternalRuntimeBridge\Lua;
use pocketmine\event\Event;
use pocketmine\plugin\EventExecutor;

class LuaEventExecutor implements EventExecutor{
	public function execute(\pocketmine\event\Listener $listener, Event $event){
		$listener->handle($event);
	}
}
