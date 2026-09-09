#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
ExternalRuntimeBridge Python 3 plugin host.

One process is created per plugin. Communication with PHP is a small
tab-delimited protocol; all fields are URL-encoded. Plugin stdout is routed to
stderr so diagnostic prints cannot corrupt the protocol stream.
"""

import inspect
import json
import os
import sys
import traceback
from urllib.parse import quote, unquote


_SOCKET = None
_SOCKET_BUFFER = bytearray()
_MAX_PROTOCOL_LINE = 1024 * 1024


class BridgeError(RuntimeError):
    """Raised when the server bridge rejects or loses an RPC call."""


def _encode(value):
    if value is None:
        value = ""
    return quote(str(value), safe="")


def _decode(value):
    return unquote(value)


def _send(tag, *fields):
    line = str(tag)
    for value in fields:
        line += "\t" + _encode(value)
    line += "\n"
    _SOCKET.sendall(line.encode("utf-8"))


def _read_protocol_line():
    while True:
        newline = _SOCKET_BUFFER.find(b"\n")
        if newline >= 0:
            line = bytes(_SOCKET_BUFFER[:newline]).decode("utf-8", "replace")
            del _SOCKET_BUFFER[:newline + 1]
            return line
        chunk = _SOCKET.recv(4096)
        if not chunk:
            raise BridgeError("PHP runtime disconnected")
        _SOCKET_BUFFER.extend(chunk)
        if len(_SOCKET_BUFFER) > _MAX_PROTOCOL_LINE:
            raise BridgeError("PHP bridge protocol line exceeds 1 MiB")



def _flex_call(callback, *preferred_args):
    try:
        signature = inspect.signature(callback)
        parameters = list(signature.parameters.values())
        positional = [
            p for p in parameters
            if p.kind in (inspect.Parameter.POSITIONAL_ONLY, inspect.Parameter.POSITIONAL_OR_KEYWORD)
        ]
        has_varargs = any(p.kind == inspect.Parameter.VAR_POSITIONAL for p in parameters)
        if has_varargs:
            return callback(*preferred_args)
        required = len([p for p in positional if p.default is inspect.Parameter.empty])
        maximum = len(positional)
        argc = min(len(preferred_args), maximum)
        if argc < required:
            return callback(*preferred_args)
        return callback(*preferred_args[:argc])
    except (TypeError, ValueError):
        return callback(*preferred_args)


class BridgeClient:
    def __init__(self):
        self._next_callback = 1
        self.callbacks = {}

    def register_callback(self, callback):
        callback_id = self._next_callback
        self._next_callback += 1
        self.callbacks[callback_id] = callback
        return callback_id

    def call_php(self, method, *args):
        _send("CALL", method, *args)
        while True:
            line = _read_protocol_line()
            parts = line.rstrip("\r\n").split("\t")
            tag = parts[0]
            values = [_decode(item) for item in parts[1:]]
            if tag == "RESULT":
                ok = values[0] == "1" if values else False
                value = values[1] if len(values) > 1 else ""
                if not ok:
                    raise BridgeError(value)
                return value
            if tag == "ERROR":
                raise BridgeError(values[-1] if values else "PHP bridge error")
            if tag == "SHUTDOWN":
                raise BridgeError("Server requested shutdown")
            # The parent should answer a CALL immediately. Other tags are
            # ignored here because callbacks are driven by the parent side.

    def value(self, method, *args):
        raw = self.call_php(method, *args)
        return raw

    def json_value(self, method, *args):
        raw = self.call_php(method, *args)
        try:
            return json.loads(raw)
        except (TypeError, ValueError):
            return None

    def register_event(self, name, callback, priority=3, ignore_cancelled=False):
        callback_id = self.register_callback(callback)
        _send("REGISTER_EVENT", name, callback_id, int(priority), 1 if ignore_cancelled else 0)
        return callback_id

    def register_command(self, name, callback, description="", usage="", permission="", aliases=None):
        callback_id = self.register_callback(callback)
        if aliases is None:
            aliases = []
        if isinstance(aliases, (tuple, list)):
            aliases = "|".join(str(x) for x in aliases)
        _send("REGISTER_COMMAND", name, callback_id, description, usage, permission, aliases)
        return callback_id

    def register_timer(self, callback, mode, delay=0, period=-1):
        callback_id = self.register_callback(callback)
        _send("REGISTER_TIMER", callback_id, mode, int(delay), int(period))
        while True:
            line = _read_protocol_line()
            parts = line.rstrip("\r\n").split("\t")
            tag = parts[0]
            values = [_decode(item) for item in parts[1:]]
            if tag == "RESULT":
                ok = values[0] == "1" if values else False
                value = values[1] if len(values) > 1 else ""
                if not ok:
                    raise BridgeError(value)
                return int(value or "0")
            if tag == "ERROR":
                raise BridgeError(values[-1] if values else "PHP bridge error")

    def cancel_task(self, task_id):
        return self.value("scheduler.cancel", int(task_id)) == "1"


_bridge = BridgeClient()


class BaseProxy:
    def __init__(self, token=None):
        self.token = token


class SenderProxy(BaseProxy):
    def sendMessage(self, message):
        return _bridge.value("sender.sendMessage", self.token, message) == "1"

    def getName(self):
        return _bridge.value("sender.getName", self.token)

    def hasPermission(self, permission):
        return _bridge.value("sender.hasPermission", self.token, permission) == "1"


class PlayerProxy(SenderProxy):
    def getId(self):
        return self.token_id

    def sendMessage(self, message):
        return _bridge.value("player.sendMessage", self.token_id, message) == "1"

    @property
    def token_id(self):
        return int(self.token)

    def getName(self):
        return _bridge.value("player.getName", self.token_id)

    def getHealth(self):
        return float(_bridge.value("player.getHealth", self.token_id) or "0")

    def setHealth(self, health):
        return _bridge.value("player.setHealth", self.token_id, health) == "1"

    def getPosition(self):
        data = _bridge.json_value("player.getPosition", self.token_id)
        data = data or [0.0, 0.0, 0.0]
        return (float(data[0]), float(data[1]), float(data[2]))

    def getPositionObject(self):
        data = _bridge.json_value("player.getPosition", self.token_id)
        data = data or [0.0, 0.0, 0.0]
        return Position(data[0], data[1], data[2])

    def teleport(self, x, y, z):
        return _bridge.value("player.teleport", self.token_id, x, y, z) == "1"

    def hasPermission(self, permission):
        return _bridge.value("player.hasPermission", self.token_id, permission) == "1"


class EntityProxy(BaseProxy):
    def __init__(self, entity_id):
        super().__init__(int(entity_id))

    def getId(self):
        return int(self.token)

    def getName(self):
        return _bridge.value("entity.getName", self.token)

    def getPosition(self):
        data = _bridge.json_value("entity.getPosition", self.token)
        data = data or [0.0, 0.0, 0.0]
        return (float(data[0]), float(data[1]), float(data[2]))

    def getPositionObject(self):
        data = _bridge.json_value("entity.getPosition", self.token)
        data = data or [0.0, 0.0, 0.0]
        return Position(data[0], data[1], data[2])


class BlockProxy(BaseProxy):
    def __init__(self, data):
        super().__init__(data or {})
        self._data = data or {}

    def getId(self):
        return int(self._data.get("id", 0))

    def getDamage(self):
        return int(self._data.get("damage", 0))

    def getName(self):
        return str(self._data.get("name", "Unknown"))

    def getPosition(self):
        return Position(
            self._data.get("x", 0.0),
            self._data.get("y", 0.0),
            self._data.get("z", 0.0),
        )


class Position:
    def __init__(self, x=0.0, y=0.0, z=0.0):
        self.x = float(x)
        self.y = float(y)
        self.z = float(z)

    def asTuple(self):
        return (self.x, self.y, self.z)

    def __iter__(self):
        return iter((self.x, self.y, self.z))

    def __repr__(self):
        return "Position({0}, {1}, {2})".format(self.x, self.y, self.z)


class WorldProxy(BaseProxy):
    def __init__(self, token):
        super().__init__(token)

    def getName(self):
        return _bridge.value("world.getName", self.token)

    def getPlayers(self):
        return [PlayerProxy(pid) for pid in (_bridge.json_value("world.getPlayers", self.token) or [])]

    def getBlock(self, x, y, z):
        data = _bridge.json_value("world.getBlock", self.token, x, y, z)
        return BlockProxy(data)

    def setBlock(self, x, y, z, block_id, damage=0):
        return _bridge.value("world.setBlock", self.token, x, y, z, block_id, damage) == "1"


class ConfigProxy:
    def get(self, key, default=None):
        raw = _bridge.value("config.get", key)
        if raw.startswith("null:"):
            return default
        if raw.startswith("bool:"):
            return raw[5:] == "1"
        if raw.startswith("int:"):
            try:
                return int(raw[4:])
            except ValueError:
                return default
        if raw.startswith("float:"):
            try:
                return float(raw[6:])
            except ValueError:
                return default
        if raw.startswith("json:"):
            try:
                return json.loads(raw[5:])
            except (TypeError, ValueError):
                return default
        if raw.startswith("string:"):
            return raw[7:]
        return raw

    def set(self, key, value):
        if isinstance(value, bool):
            encoded = "bool:" + ("1" if value else "0")
        elif isinstance(value, int):
            encoded = "int:" + str(value)
        elif isinstance(value, float):
            encoded = "float:" + str(value)
        elif value is None:
            encoded = "null:"
        elif isinstance(value, (list, tuple, dict)):
            encoded = "json:" + json.dumps(value, ensure_ascii=False)
        else:
            encoded = "string:" + str(value)
        return _bridge.value("config.set", key, encoded) == "1"

    def save(self):
        return _bridge.value("config.save") == "1"

    def reload(self):
        return _bridge.value("config.reload") == "1"


class EventProxy:
    def get(self, key, default=None):
        return self._fields.get(key, default)

    def __init__(self, name, fields):
        self._name = name
        self._fields = fields

    def getName(self):
        return self._name

    def getPlayer(self):
        token = self._fields.get("player", "")
        if token.startswith("player:"):
            return PlayerProxy(int(token[7:]))
        return None

    def getSender(self):
        token = self._fields.get("sender", "")
        if token.startswith("player:"):
            return PlayerProxy(int(token[7:]))
        return SenderProxy("console") if token == "console" else None

    def getEntity(self):
        token = self._fields.get("entity", "")
        if token.startswith("entity:"):
            return EntityProxy(int(token[7:]))
        return None

    def getWorld(self):
        token = self._fields.get("world", "")
        if token.startswith("world:"):
            return WorldProxy(token)
        return None

    def getLevel(self):
        return self.getWorld()

    def getBlock(self):
        raw = self._fields.get("block", "")
        if not raw:
            return None
        try:
            return BlockProxy(json.loads(raw))
        except (TypeError, ValueError):
            return None

    def getMessage(self):
        return self._fields.get("message", "")

    def getJoinMessage(self):
        return self._fields.get("joinMessage", "")

    def getQuitMessage(self):
        return self._fields.get("quitMessage", "")

    def getReason(self):
        return self._fields.get("reason", "")

    def getAction(self):
        return self._fields.get("action", "")

    def getCommand(self):
        return self._fields.get("command", "")

    def isCancelled(self):
        return self._fields.get("cancelled", "0") == "1"

    def setCancelled(self, value=True):
        return _bridge.value("event.setCancelled", 1 if value else 0) == "1"


class PluginProxy(BaseProxy):
    def getName(self):
        return _bridge.value("plugin.getName")

    def getDataFolder(self):
        return _bridge.value("plugin.getDataFolder")

    def getConfig(self):
        return ConfigProxy()

    def getServer(self):
        return server

    def getLogger(self):
        return logger


class LoggerProxy:
    def info(self, message):
        return _bridge.value("server.log", message) == "1"

    def warning(self, message):
        return _bridge.value("server.log", "[WARN] " + str(message)) == "1"

    def error(self, message):
        return _bridge.value("server.log", "[ERROR] " + str(message)) == "1"

    def debug(self, message):
        return _bridge.value("server.log", "[DEBUG] " + str(message)) == "1"


class ServerProxy:
    def getName(self):
        return _bridge.value("server.getName")

    def getPort(self):
        return int(_bridge.value("server.getPort") or "0")

    def getMotd(self):
        return _bridge.value("server.getMotd")

    def getDataPath(self):
        return _bridge.value("server.getDataPath")

    def getOnlinePlayerCount(self):
        return int(_bridge.value("server.getOnlinePlayerCount") or "0")

    def getOnlinePlayers(self):
        return [PlayerProxy(pid) for pid in (_bridge.json_value("server.getOnlinePlayers") or [])]

    def getPlayer(self, name):
        token = _bridge.value("server.getPlayer", name)
        if token.startswith("player:"):
            return PlayerProxy(int(token[7:]))
        return None

    def getDefaultWorld(self):
        token = _bridge.value("server.getDefaultWorld")
        return WorldProxy(token) if token.startswith("world:") else None

    def getWorld(self, name):
        token = _bridge.value("server.getWorld", name)
        return WorldProxy(token) if token.startswith("world:") else None

    def broadcastMessage(self, message):
        return _bridge.value("server.broadcast", message) == "1"

    def dispatchCommand(self, sender, command_line):
        token = "console"
        if isinstance(sender, PlayerProxy):
            token = "player:" + str(sender.token_id)
            sender_id = sender.token_id
        elif isinstance(sender, SenderProxy):
            sender_id = 0
        else:
            sender_id = 0
        return _bridge.value("server.dispatchCommand", sender_id, command_line) == "1"

    def registerEvent(self, name, callback, priority=3, ignoreCancelled=False):
        return _bridge.register_event(name, callback, priority, ignoreCancelled)

    def registerCommand(self, name, callback, description="", usage="", permission="", aliases=None):
        return _bridge.register_command(name, callback, description, usage, permission, aliases)

    def scheduleRepeatingTask(self, callback, period):
        return _bridge.register_timer(callback, "repeat", 0, period)

    def scheduleDelayedTask(self, callback, delay):
        return _bridge.register_timer(callback, "delay", delay, -1)

    def scheduleDelayedRepeatingTask(self, callback, delay, period):
        return _bridge.register_timer(callback, "delay_repeat", delay, period)

    def cancelTask(self, task_id):
        return _bridge.cancel_task(task_id)


server = ServerProxy()
logger = LoggerProxy()
plugin = PluginProxy()


def _call_lifecycle(namespace, name):
    callback = namespace.get(name)
    if callback is None:
        return True
    result = _flex_call(callback)
    return True if result is None else bool(result)


def _dispatch_invoke(namespace, callback_id, event_name, fields):
    callback = _bridge.callbacks.get(int(callback_id))
    if callback is None:
        raise BridgeError("Unknown Python callback id: " + str(callback_id))

    if event_name == "command":
        token = fields.get("sender", "console")
        if token.startswith("player:"):
            sender = PlayerProxy(int(token[7:]))
        else:
            sender = SenderProxy("console")
        try:
            args = json.loads(fields.get("args", "[]"))
        except (TypeError, ValueError):
            args = []
        result = _flex_call(callback, sender, args)
        return True if result is None else bool(result)

    if event_name == "timer":
        tick = int(fields.get("tick", "0") or "0")
        result = _flex_call(callback, tick)
        return True if result is None else bool(result)

    event = EventProxy(event_name, fields)
    _flex_call(callback, event)
    return True


def _load_plugin(main_file):
    plugin_root = os.path.dirname(os.path.abspath(main_file))
    if plugin_root not in sys.path:
        sys.path.insert(0, plugin_root)

    namespace = {
        "__file__": os.path.abspath(main_file),
        "__name__": "__external_python_plugin__",
        "server": server,
        "plugin": plugin,
        "logger": logger,
    }
    code = compile(open(main_file, "rb").read(), main_file, "exec")
    exec(code, namespace, namespace)
    return namespace


def main():
    global _SOCKET

    if len(sys.argv) != 5:
        print("Usage: python_runtime.py <host> <port> <token> <plugin.py>", file=sys.stderr)
        return 2

    host_name = sys.argv[1]
    port = int(sys.argv[2])
    token = sys.argv[3]
    main_file = os.path.abspath(sys.argv[4])

    import socket

    sock = socket.create_connection((host_name, port), timeout=5.0)
    sock.settimeout(None)
    _SOCKET = sock
    # Plugin print() calls must never accidentally become protocol traffic.
    sys.stdout = sys.stderr
    try:
        _send("HELLO", token)
                # Authentication acknowledgement is required before loading plugin code.
        auth_line = _read_protocol_line()
        auth_parts = auth_line.split("\t")
        if not auth_parts or auth_parts[0] != "HELLO_OK":
            raise BridgeError("Python bridge authentication rejected")

        namespace = _load_plugin(main_file)
        _call_lifecycle(namespace, "onLoad")
        _send("READY")

        while True:
            line = _read_protocol_line()
            parts = line.split("\t")
            tag = parts[0]
            values = [_decode(item) for item in parts[1:]]

            try:
                if tag == "SHUTDOWN":
                    return 0

                if tag == "CALL_FUNCTION":
                    name = values[0] if values else ""
                    callback = namespace.get(name)
                    result = True if callback is None else _call_lifecycle(namespace, name)
                    _send("FUNCTION_RETURN", 1 if result else 0, "")
                    continue

                if tag == "INVOKE":
                    callback_id = int(values[0]) if values else 0
                    event_name = values[1] if len(values) > 1 else ""
                    fields = {}
                    tail = values[2:]
                    for i in range(0, len(tail) - 1, 2):
                        fields[tail[i]] = tail[i + 1]
                    result = _dispatch_invoke(namespace, callback_id, event_name, fields)
                    _send("RETURN", 1 if result else 0, "")
                    continue

            except BaseException as exc:
                trace = traceback.format_exc()
                _send("CALLBACK_ERROR", str(exc), trace)
                continue

    except BaseException as exc:
        traceback.print_exc(file=sys.stderr)
        try:
            _send("ERROR", "Plugin startup failed: " + str(exc))
        except BaseException:
            pass
        return 1
    finally:
        try:
            sock.close()
        except BaseException:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
