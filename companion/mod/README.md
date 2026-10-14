# MC Toolkit Bridge: mod loaders

One server-side jar, `mctoolkit-bridge.jar` (mod id `mctoolkit_bridge`), that loads on **Fabric,
Quilt, NeoForge and Forge** from Minecraft 1.20.1 to the unobfuscated 26.x line. It writes live game
data into `.toolkit/*.json` for the MC Toolkit panel plugin, exactly as specified in
[`../PROTOCOL.md`](../PROTOCOL.md). It opens no port and registers no commands, events, network
channels, mixins or config. It needs no Fabric API. On a client it does nothing.

The panel installs it as `mods/mctoolkit-bridge.jar`; `./gradlew bundle` copies the release build to
`resources/bridge/mctoolkit-bridge.jar`.

## What it writes

Every 5 seconds, atomically (`<name>.tmp` + `ATOMIC_MOVE`, falling back to `REPLACE_EXISTING`), into
`.toolkit/` (or `-Dmctoolkit.bridge.dir` / `MCTOOLKIT_BRIDGE_DIR`):

| File | Content |
|---|---|
| `state.json` | online players: name, game mode, world/dimension, position, health, food, XP, ping, op, join time |
| `players/<uuid>.json` | inventory (slots 0–35), armor, offhand, ender chest, effects. Rewritten only when the content (everything but the meta block) changed. Deleted on the next cycle once the player is offline. |
| `performance.json` | TPS (latest 5 s, plus 1/5/15-minute averages, capped at 20), MSPT, and per level: players, loaded chunks, entity total and the top 10 entity types |
| `map.json` | overworld day time (0–23999) and player pins |

On shutdown (console `stop`, or the JVM shutting down on SIGTERM) the bridge writes `state.json` and
`map.json` with no players and `"running": false`, and deletes every player file. It also rewrites
`performance.json` with `"running": false` (keeping the last numbers), so the panel's freshness
check treats it as stopped right away instead of after 30 s.

Choices the protocol leaves open:

- **Level names.** Fabric and Forge keep every dimension in one save folder (`level-name`, normally
  `world`). Levels are named the Bukkit way so the panel can tell them apart: `world`,
  `world_nether`, `world_the_end`, and `world_<namespace>_<path>` for modded dimensions. The
  `dimension` field is `overworld` / `nether` / `end` / `namespace:path`.
- **`joined_at`** is the first 5-second cycle that saw the player online, so it can be up to one
  interval late. The bridge registers no join event.
- **`chunks`** is `ServerChunkCache#getLoadedChunksCount()`, the number of chunk holders the chunk
  map tracks (border chunks included, so 3×3 force-loaded chunks read as several hundred). Since
  1.21.9 vanilla has no spawn chunks, so an empty server correctly reports 0.
- **TPS** is measured: the tick counter is sampled against wall time each cycle. An empty server on
  1.21.2+ pauses itself (`pause-when-empty-seconds`, default 60) and stops counting ticks. The bridge
  detects the pause (`emptyTicks`) and counts paused time at full speed, as Paper's loop-based TPS
  does, so an idle server does not show TPS 0.
- **`ping`** is `null` for a player without a network connection (fake players).

## Supported versions

Minimum Minecraft 1.20.1. Bytecode is Java 17, so the jar runs on every Java a server uses (17/21/25).

| Loader | Versions | Runtime names | Metadata the loader reads |
|---|---|---|---|
| Fabric | 1.20.1 – 1.21.11 | intermediary (table) | `fabric.mod.json`, `server` entrypoint |
| Fabric | 26.x | Mojang | same |
| Quilt | same as Fabric | same as Fabric | `fabric.mod.json` (Quilt loads Fabric mods) |
| NeoForge | 1.20.2 – 1.20.4 | Mojang on 1.20.4 (SRG table also present) | `META-INF/mods.toml` + `@net.neoforged.fml.common.Mod` |
| NeoForge | 1.20.5 – 1.21.x, 26.x | Mojang | `META-INF/neoforge.mods.toml` |
| Forge | 1.20.1 – 1.20.4 | SRG (table) | `META-INF/mods.toml` + `@net.minecraftforge.fml.common.Mod` |
| Forge | 1.20.6 – 1.21.x, 26.x | Mojang | same |
| NeoForge for 1.20.1 (Forge fork) | 1.20.1 | SRG | same as Forge; reported as `neoforge` via its Maven group |

The namespace is detected at runtime, never configured. Rows without a verified server below
(NeoForge 1.20.1/1.20.2–1.20.3, Forge 1.20.2–1.20.6 and other 1.21.x builds) rely on the same
detection and tables, but were not started.

Verified on real headless servers (flat world, `tools/livetest`). "Self-test" means the full player
path was exercised with a detached `ServerPlayer` (see Testing). Every row passed every check.

| Server | Java | Names | Runs |
|---|---|---|---|
| NeoForge 1.21.1 (21.1.259) | 21 | mojang | self-test (stop + SIGTERM), release jar |
| Forge 1.20.1 (47.4.28) | 17 | srg | self-test (stop + SIGTERM), release jar |
| Fabric 1.21.11 (Loader 0.19.5) | 21 | intermediary | self-test (stop + SIGTERM), release jar |
| Fabric 26.3 (Loader 0.19.5) | 25 | mojang | self-test (stop + SIGTERM), release jar |
| Quilt 1.21.1 (Loader 0.31.0-beta.4) | 21 | intermediary | self-test |
| Forge 1.21.1 (52.1.19) | 21 | mojang | self-test |
| Forge 26.3 (66.0.11) | 25 | mojang | self-test, release jar |
| NeoForge 26.3 (26.3.0.77) | 25 | mojang | self-test |
| NeoForge 1.20.4 (20.4.251) | 17 | mojang | self-test |
| Fabric 1.20.1 (Loader 0.19.5) | 17 | intermediary | self-test |

## How it works

- **One jar, no loader on the classpath.** Each loader finds its own entrypoint:
  `FabricEntrypoint` (`DedicatedServerModInitializer`, which Fabric/Quilt only call on a dedicated
  server), `NeoForgeEntrypoint` and `ForgeEntrypoint` (`@Mod`, which check `FMLEnvironment.dist`
  and do nothing on a client). The loader types come from stub sources in `src/stubs`. They are
  compiled against but never packaged; FML finds `@Mod` by its descriptor. The only other
  compile-time dependency is `slf4j-api` (compileOnly; every server ships it).
- **Server lookup without events.** A daemon thread (`MCToolkit-Bridge`) polls the loader for the
  running server: `FabricLoader.getInstance().getGameInstance()`, or
  `ServerLifecycleHooks.getCurrentServer()` on Forge/NeoForge, all by reflection. It then waits until
  the server ticks.
- **Threading.** Every 5 s the thread samples the tick counter, then hops onto the server thread
  with `((Executor) server).execute(...)`. `Executor` is a JDK interface, so mappings never rename
  it. On the server thread it copies everything into plain Java objects. If the server thread does
  not answer within 4 s, only `performance.json` is refreshed. The task runs only on the real server
  thread, never inline. JSON is rendered and written back on the bridge thread.
- **Reflection with name tables.** Every Minecraft member is named in Mojang terms
  (`tools/members.txt`). At startup the bridge detects the runtime namespace by probing
  `MinecraftServer#getPlayerList()` (Mojang name, intermediary `method_3760`, SRG `m_6846_`). It then
  resolves every member eagerly through the matching table and logs a capability summary, e.g.
  `Capabilities: 25/25 available [...]` followed by the members absent on this version. Where
  Minecraft changed an API, both shapes are resolved and the one that exists is used. Examples:
  `isOp(GameProfile)` vs `isOp(NameAndId)`, three generations of custom item names, `getDayTime()`
  vs 26.x `getOverworldClockTime()`, and `ServerPlayer.latency` vs `connection.latency()`.
  Anything missing degrades that field to `null` and is logged once. Nothing throws into the server.

## Field availability

All 25 capabilities resolved on every server in the matrix, so no field is structurally `null` on
any tested loader/version. Version-specific members that are expected to be absent are listed in
the startup log (e.g. on 1.21.11: `getAverageTickTime()`, `isOp(GameProfile)`,
`ServerPlayer#latency`, `hasCustomHoverName()`, `getOverworldClockTime()`). Values that can be
`null` at runtime: `ping` without a network connection; `minecraft` if the version string is
unavailable; any field whose read throws (logged once).

The self-test covers every player field on a real `ServerPlayer`, with two exceptions. **`ping` for
a connected player** (`connection.latency()` / the `latency` field) and **`op: true`** are verified
by member resolution plus the `null`/`false` path only, because no client can connect.

## Name tables

`src/main/resources/io/github/palmeany/mctoolkit/bridge/names/` (plus `*-selftest.tsv` in
`src/selftest`) is generated, never edited by hand:

```
python3 tools/gen_names.py      # from companion/mod; needs curl
```

For every release from 1.20.1 to 1.21.11 the script downloads and joins, on the obfuscated names:

- Mojang client mappings (piston-meta, ProGuard format): Mojang name to obfuscated name
- Fabric intermediary v2 (`maven.fabricmc.net`, tiny v2): obfuscated name to `class_`/`method_`/`field_`
- MCPConfig `joined.tsrg` (`maven.minecraftforge.net`, tsrg2, 1.20.1–1.20.4): obfuscated name to `m_`/`f_`

Intermediary lists an overriding method only on the supertype that introduced it. The script falls
back to the declaring supertype; a wrong candidate is harmless because it does not exist on the
real owner. Downloads are cached in `build/mappings-cache`. A per-release availability matrix of every
member is written to `build/names-report.txt`.

Regenerate after adding a member to `tools/members.txt` (then use it in `Mc.java`). New Minecraft
releases are unobfuscated (26.1+), so they need no table rows. Only extend
`INTERMEDIARY_RANGE` / `SRG_VERSIONS` in the script if an obfuscated release must be added.

## Building

```
./gradlew build          # build/libs/mctoolkit-bridge.jar (release) + unit tests
./gradlew bundle         # copies it to ../../resources/bridge/mctoolkit-bridge.jar
./gradlew selftestJar    # build/selftest/mctoolkit-bridge-selftest.jar (development only)
```

Gradle 9.8 wrapper with the foojay toolchain resolver: JDK 17, `release = 17`. The version comes
from `../VERSION`, which is shared with the Bukkit bridge.

## Testing

- **Unit tests** (`src/test`) cover JSON rendering and rounding, TPS windows / idle pause / counter
  wrap-around, the protocol shape of every file, change detection and deletion of player files,
  level naming, and the packaged tables.
- **Live tests** (`tools/livetest`):
  1. `fetch_server.py TYPE VERSION DIR` installs a server package from the MCJars API.
  2. `start_server.sh DIR PORT JAVA JAR SELFTEST -- <launch args>` starts a fresh flat world on
     127.0.0.1.
  3. `validate.py DIR LABEL [--selftest] [--stop console]` checks the run.

  `validate.py` types `forceload`/`summon`/`time set` on the console and validates every file version
  against the protocol. It checks the 5 s cadence, TPS/MSPT sanity, that chunk counts, entity counts
  (3 cows + 2 armor stands) and day time follow the commands, and the shutdown write. It then stops
  the server by PID.
- **Self-test.** There is no Minecraft client, so `mctoolkit-bridge-selftest.jar` started with
  `-Dmctoolkit.bridge.selftest=true` builds a real but detached `ServerPlayer` on the server thread.
  The player has a damaged, enchanted, renamed sword, torches, diamonds, a damaged helmet, a shield,
  an ender-chest stack and Speed II. It is fed through the normal player path for a few cycles,
  changes one stack, then leaves. The harness checks every field and the
  write-on-change/delete-on-leave timeline. The release jar does not contain this code.
