# External Plugin API

## Common lifecycle

Both languages support:

- `onLoad`
- `onEnable`
- `onDisable`

## Events

Use a documented alias such as `playerJoin` or a complete event class name known by the server.

Lua:

```lua
Events.on("playerJoin", function(event)
    local player = event:getPlayer()
    if player then
        player:sendMessage("joined")
    end
end, 3, false)
```

Python:

```python
def joined(event):
    player = event.getPlayer()
    if player:
        player.sendMessage("joined")

server.registerEvent("playerJoin", joined, 3, False)
```

`3` is `EventPriority::NORMAL` in API 2.0.0.

## Commands

Lua:

```lua
Commands.register("hello", function(sender, args)
    sender:sendMessage("hello")
    return true
end, "Greeting command.", "/hello", "", {"hi"})
```

Python:

```python
def hello(sender, args):
    sender.sendMessage("hello")
    return True

server.registerCommand("hello", hello, "Greeting command.", "/hello", "", ["hi"])
```

## Configuration

The configuration file is `config.yml` in the external plugin directory.

Lua uses `Config.get`, `Config.set`, `Config.save` and `Config.reload`.

Python uses `plugin.getConfig()` and the matching methods on the returned proxy.

## Player and world proxies

Only simple values cross the process boundary. A player proxy can provide name, health, position, permissions, message sending and teleportation. World proxies provide name, players, block reads and block writes.

The bridge should be extended rather than exposing arbitrary PHP reflection or object serialization.
