# MCToolkitBridge (Bukkit family)

Server-side companion of the MC Toolkit panel plugin. Every 5 seconds it writes live game data as JSON into
`.toolkit/` in the server's working directory, where the panel reads it through Wings. It only writes files:
no commands, no ports, no configuration. The file format is specified in [`../PROTOCOL.md`](../PROTOCOL.md).

| File | Content |
|---|---|
| `.toolkit/state.json` | online players: mode, world, dimension, position, health, food, XP, ping, op, join time |
| `.toolkit/players/<uuid>.json` | per online player: inventory, armor, offhand, ender chest, effects (rewritten only when changed, deleted when the player leaves) |
| `.toolkit/performance.json` | TPS (latest + 1/5/15 min), MSPT, per world: players, loaded chunks, entity total, top 10 entity types |
| `.toolkit/map.json` | overworld day time and player pins |

Writes are atomic (`<name>.tmp` + rename). On a clean shutdown the files are rewritten with `"running": false`
and no players. The directory can be moved with `-Dmctoolkit.bridge.dir=<path>` or `MCTOOLKIT_BRIDGE_DIR`.

## Supported servers

One jar (Java 8 bytecode) for Spigot, Paper, Purpur, Pufferfish, Leaves, Folia and Bukkit-loading hybrids
(Mohist, Arclight), Minecraft 1.8.8 through 26.x. It compiles against the 1.8.8 API; everything newer is
reached through reflection and reported as `null` where a server does not have it.

| Platform | TPS | MSPT | Ping | Entities per world |
|---|---|---|---|---|
| Paper and forks (Purpur, Pufferfish, ...) | `Server#getTPS()` (`tps` = 1-minute value) | `Server#getAverageTickTime()` (Paper 1.15+), else `null` | `Player#getPing()` (1.16.5+), older: server player field | total + top 10 by type |
| Spigot / CraftBukkit | own 1-tick counter (`tps` = last 5 s) | `null` | `Player#getPing()` (1.17+), older: server player field | total + top 10 by type |
| Folia | worst of each world's spawn region (`tps` = 5 s); global region when no spawn is loaded | same regions, read inside a region task | `Player#getPing()` | total and chunks from thread-safe counters; by type `null` |

On Folia nothing is read off its owning thread: each player is read on its own EntityScheduler, world counters
come from thread-safe server counters, and the writer runs on the async scheduler. On Spigot/Paper a
main-thread task collects and an async task writes, so there is no disk IO on the main thread.

## Build

```
./gradlew build            # build/libs/MCToolkitBridge.jar (+ unit tests)
./gradlew selftestJar      # build/libs/MCToolkitBridgeSelfTest.jar — development only, never ship it
```

Gradle 9.8 runs on Java 17+; the JDK 25 toolchain is downloaded automatically if missing and compiles with
`--release 8`. The shipped copy lives in `../../resources/bridge/MCToolkitBridge.jar`.

## Testing

- `./gradlew test`: JUnit 5 tests for the JSON writer, item/player/world serialisation (with
  `java.lang.reflect.Proxy` stand-ins for Bukkit objects), the file writer (atomic writes, unchanged files not
  rewritten, offline players' files deleted, shutdown write), the TPS meter and version parsing.
- Live servers: put both jars into `plugins/` of a local test server. The self-test plugin waits about 12 s,
  then pushes a fake player through the bridge (a Proxy player, or a real unconnected server player on 1.8.8).
  It checks every file against the protocol, logs `[SELFTEST] PASS/FAIL` lines and a `RESULT` line, and stops the
  server. Optional expectations: `-Dselftest.platform=paper`, `-Dselftest.minecraft=1.21.11`,
  `-Dselftest.mspt=null`. Use `-Dselftest.stay=true` to keep the server running.
