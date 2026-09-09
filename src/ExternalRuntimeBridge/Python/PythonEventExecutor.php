<?php
namespace ExternalRuntimeBridge\Python;

use pocketmine\event\Event;
use pocketmine\plugin\EventExecutor;

class PythonEventExecutor implements EventExecutor{
	public function execute(\pocketmine\event\Listener $listener, Event $event){
		$listener->handle($event);
	}
}
