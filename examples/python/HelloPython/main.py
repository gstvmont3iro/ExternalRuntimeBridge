def on_join(event):
    player = event.getPlayer()
    if player:
        player.sendMessage("§aHello from Python!")

def hello(sender, args):
    sender.sendMessage("§bHello from an external Python plugin.")
    return True

def onEnable():
    server.registerEvent("playerJoin", on_join)
    server.registerCommand("hello", hello, "Sends a greeting.", "/hello", "", ["hi"])
    server.scheduleRepeatingTask(lambda tick: logger.info("HelloPython heartbeat"), 20 * 60)

def onDisable():
    logger.info("HelloPython disabled")
