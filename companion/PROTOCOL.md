# MC Toolkit companion bridge — file protocol v1

The bridge is a server-side plugin/mod that writes live game data into plain JSON files in the
server's working directory. The panel never talks to the game directly: it reads these files
through Wings, exactly like every other server file. The bridge opens no port and accepts no
input — it only writes.

## Location and timing

- Directory: `.toolkit/` relative to the server's working directory (the egg's `/home/container`).
  Override with the JVM system property `-Dmctoolkit.bridge.dir=<path>` or the environment
  variable `MCTOOLKIT_BRIDGE_DIR` (relative paths resolve against the working directory).
- Every file is rewritten every **5 seconds** (the `interval` in the meta block), always
  **atomically**: write `<name>.tmp` in the same directory, then rename over the target
  (`ATOMIC_MOVE`, falling back to `REPLACE_EXISTING`). Readers must never see a half file.
- UTF-8, no BOM, compact JSON (no pretty printing needed). Numbers are plain JSON numbers;
  doubles rounded to 2 decimals (positions, health, TPS, MSPT).
- On a clean shutdown the bridge rewrites `state.json` and `map.json` with an empty player list
  and `"running": false` in the meta block. The panel also treats any file whose `updated_at` is
  older than 30 seconds as stale (server stopped or crashed).
- Per-player detail files of players who went offline are deleted on the next cycle.

## Meta block (`bridge`, present in every file)

```json
"bridge": {
  "protocol": 1,
  "version": "1.0.0",
  "platform": "paper",          // spigot | paper | purpur | folia | fabric | quilt | neoforge | forge | unknown
  "software": "Paper version 1.21.11-...",   // the server's own version string
  "minecraft": "1.21.11",       // Minecraft version, null when unknown
  "updated_at": 1791956941,     // unix seconds (UTC) of this write
  "interval": 5,                // seconds between writes
  "running": true               // false only in the final write on shutdown
}
```

## `.toolkit/state.json` — online players (light, read on every Players page poll)

```json
{
  "bridge": { ... },
  "players": {
    "0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10": {
      "name": "Steve",
      "mode": "survival",          // survival | creative | adventure | spectator
      "world": "world_nether",     // world/level folder name as the server knows it
      "dimension": "nether",       // overworld | nether | end | <namespace:path> for others
      "x": 156.5, "y": 70.0, "z": -307.25,
      "health": 18.5, "max_health": 20.0,
      "food": 17, "saturation": 3.2,
      "level": 30, "exp": 0.42,    // exp = progress to next level, 0..1
      "ping": 42,                  // ms, null when the platform does not expose it
      "op": false,
      "joined_at": 1791950000      // unix seconds of this session's join, null if unknown
    }
  }
}
```

Keys of `players` are dashed lowercase UUIDs. Fields a platform cannot provide are `null`, never
omitted.

## `.toolkit/players/<uuid>.json` — per online player detail (read when a player is opened)

```json
{
  "bridge": { ... },
  "uuid": "0c9a1c4e-...", "name": "Steve",
  "inventory": [ {"slot": 0, "id": "minecraft:diamond_sword", "count": 1, "name": "Excalibur",
                  "enchanted": true, "damage": 12, "max_damage": 1561} ],
  "armor": { "head": null, "chest": {item}, "legs": null, "feet": {item} },
  "offhand": {item} | null,
  "ender_chest": [ {item with slot 0..26} ],
  "effects": [ {"id": "minecraft:speed", "amplifier": 1, "duration": 600} ]
}
```

- `inventory` lists only non-empty slots of the main inventory: slots 0–8 hotbar, 9–35 storage.
- An item is `{"slot"?, "id", "count", "name", "enchanted", "damage", "max_damage"}`; `name` is
  the plain-text custom name or `null`; `damage`/`max_damage` are `null` for undamageable items.
- `duration` is in ticks (`-1` = infinite).
- Files are only rewritten when their content changed.

## `.toolkit/performance.json`

```json
{
  "bridge": { ... },
  "tps": 19.98,                                   // latest, capped at 20 (Folia: see below)
  "mspt": 12.31,                                  // average milliseconds per tick, null if unknown
  "tps_avg": { "one": 19.97, "five": 19.99, "fifteen": 20.0 },
  "worlds": [
    { "name": "world", "dimension": "overworld", "players": 3, "chunks": 961,
      "entity_count": 1300, "entities": { "minecraft:item": 612, "minecraft:zombie": 188 } }
  ]
}
```

- `entities` holds at most the 10 most common entity types of that world; `entity_count` is the
  full total.
- Folia has no single main thread: `tps`/`mspt` are those of the region holding the overworld
  spawn (worst of all worlds' spawn regions), and per-world counts that cannot be read safely
  off-thread are `null`.

## `.toolkit/map.json` — map pins (read by the Map page every few seconds)

```json
{
  "bridge": { ... },
  "time": 6000,                                   // overworld day time in ticks (0..23999)
  "players": [
    { "uuid": "0c9a…", "name": "Steve", "world": "world", "dimension": "overworld",
      "x": 156.5, "y": 70.0, "z": -307.25 }
  ]
}
```

## Installed-jar names (the panel's one-click installer relies on them)

- Bukkit family: `plugins/MCToolkitBridge.jar` (plugin name `MCToolkitBridge`).
- Fabric / Quilt / NeoForge / Forge: `mods/mctoolkit-bridge.jar` (mod id `mctoolkit_bridge`).

The panel replaces any file matching `MCToolkitBridge*.jar` / `mctoolkit-bridge*.jar` when it
installs or updates the bridge.
