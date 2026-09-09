local function on_join(event)
    local player = event:getPlayer()
    if player then
        player:sendMessage("§aHello from Lua!")
    end
end

local function hello(sender, args)
    sender:sendMessage("§bHello from an external Lua plugin.")
    return true
end

function onEnable()
    Events.on("playerJoin", on_join, 3, false)
    Commands.register("hello", hello, "Sends a greeting.", "/hello", "", {"hi"})
    Scheduler.runRepeating(20 * 60, function()
        Server.log("HelloLua heartbeat")
    end)
end

function onDisable()
    Server.log("HelloLua disabled")
end
