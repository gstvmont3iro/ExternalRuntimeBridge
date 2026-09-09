#!/bin/sh
set -eu
SRC="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
OUT="$SRC/external_lua_host"
CC_BIN="${CC:-cc}"
if [ -f "$SRC/lib/liblua5.4.so.0" ]; then
  exec "$CC_BIN" -O2 -std=gnu99 "$SRC/external_lua_host.c" -o "$OUT" \
    -L"$SRC/lib" -Wl,-rpath,'$ORIGIN/lib' -Wl,-z,origin -Wl,-l:liblua5.4.so.0
fi
LUA_LIB="${LUA_LIB:-}"
if [ -n "$LUA_LIB" ]; then
  exec "$CC_BIN" -O2 -std=gnu99 "$SRC/external_lua_host.c" -o "$OUT" "$LUA_LIB"
fi
for lib in /usr/lib/x86_64-linux-gnu/liblua5.4.so /usr/lib/aarch64-linux-gnu/liblua5.4.so /lib/x86_64-linux-gnu/liblua5.4.so.0 /lib/aarch64-linux-gnu/liblua5.4.so.0; do
  if [ -e "$lib" ]; then
    exec "$CC_BIN" -O2 -std=gnu99 "$SRC/external_lua_host.c" -o "$OUT" "$lib"
  fi
done
echo "Lua 5.4 shared library not found. Set LUA_LIB to its full path." >&2
exit 1
