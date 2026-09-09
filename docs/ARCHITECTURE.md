# Architecture

The core server sees only one normal PHP plugin: `ExternalRuntimeBridge`.

At enable time:

1. `Main` creates `ExternalPluginManager`.
2. The manager scans the configured external directory.
3. Each manifest is parsed and validated without registering it with the native plugin loader.
4. A language adapter creates a `PluginBase` proxy.
5. The proxy starts one persistent runtime process.
6. Runtime code registers events, commands and timers back to PHP using a small line protocol.
7. PHP translates runtime messages into API 2.0.0 operations using only the public server APIs.

## Why not modify the core?

The API exposes public hooks for event registration, command map registration and scheduler tasks. Those hooks are enough to emulate a plugin facade after the bridge plugin itself has loaded. Modifying `PluginManager`, `PluginDescription` or `Server` would create a fork-specific contract and defeat the drop-in objective.

## IPC choices

Lua uses stdin/stdout because the native host is tiny and local. Python uses a localhost TCP socket with a random token so the Python helper cannot accidentally mix its protocol with standard output.

There is one persistent process per external plugin. No new process is created per event or timer.

## Object model

Runtime code never receives PHP object references. Players, senders, worlds, entities and blocks are represented by IDs and serialized values. Every operation crosses the bridge explicitly.

## Failure model

A runtime start error, callback error or runtime disconnect is logged against that external plugin. The bridge disables that plugin, unregisters its listeners and cancels its tasks. Other external plugins continue running.

This is fault isolation, not a security sandbox.
