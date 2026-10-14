package io.github.palmeany.mctoolkit.bridge;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.DirectoryStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Renders the protocol files and writes them atomically ({@code <name>.tmp} + rename) into the
 * bridge directory. Per-player detail files are only rewritten when their content changed and are
 * deleted once the player is gone.
 */
final class Output {

    private static final Pattern PLAYER_FILE = Pattern.compile("[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\.json");

    final Path dir;
    private final Path playersDir;
    /** uuid -> last written body (everything but the meta block). */
    private final Map<String, String> playerBodies = new HashMap<>();

    Output(Path dir) {
        this.dir = dir;
        this.playersDir = dir.resolve("players");
    }

    // ------------------------------------------------------------------ documents

    void writeState(String meta, List<Snapshot.Player> players, Map<String, Long> joinedAt) {
        Json json = new Json().beginObject().name("bridge").raw(meta).name("players").beginObject();
        for (Snapshot.Player p : players) {
            json.name(p.uuid).beginObject()
                    .field("name", p.name)
                    .field("mode", p.mode)
                    .field("world", p.world)
                    .field("dimension", p.dimension)
                    .decimalField("x", p.x)
                    .decimalField("y", p.y)
                    .decimalField("z", p.z)
                    .decimalField("health", p.health)
                    .decimalField("max_health", p.maxHealth)
                    .field("food", p.food)
                    .decimalField("saturation", p.saturation)
                    .field("level", p.level)
                    .decimalField("exp", p.exp)
                    .field("ping", p.ping)
                    .field("op", p.op)
                    .field("joined_at", joinedAt.get(p.uuid))
                    .endObject();
        }
        write("state.json", json.endObject().endObject().toString());
    }

    void writeMap(String meta, Long dayTime, List<Snapshot.Player> players) {
        Json json = new Json().beginObject().name("bridge").raw(meta).field("time", dayTime).name("players").beginArray();
        for (Snapshot.Player p : players) {
            json.beginObject()
                    .field("uuid", p.uuid)
                    .field("name", p.name)
                    .field("world", p.world)
                    .field("dimension", p.dimension)
                    .decimalField("x", p.x)
                    .decimalField("y", p.y)
                    .decimalField("z", p.z)
                    .endObject();
        }
        write("map.json", json.endArray().endObject().toString());
    }

    void writePerformance(String meta, Double tps, Double one, Double five, Double fifteen, Double mspt, List<Snapshot.World> worlds) {
        Json json = new Json().beginObject().name("bridge").raw(meta)
                .decimalField("tps", tps)
                .decimalField("mspt", mspt)
                .name("tps_avg").beginObject()
                .decimalField("one", one)
                .decimalField("five", five)
                .decimalField("fifteen", fifteen)
                .endObject()
                .name("worlds").beginArray();
        for (Snapshot.World w : worlds) {
            json.beginObject()
                    .field("name", w.name)
                    .field("dimension", w.dimension)
                    .field("players", w.players)
                    .field("chunks", w.chunks)
                    .field("entity_count", w.entityCount)
                    .name("entities");
            if (w.topEntities == null) {
                json.value((String) null);
            } else {
                json.beginObject();
                for (Map.Entry<String, Integer> e : w.topEntities.entrySet()) {
                    json.field(e.getKey(), e.getValue());
                }
                json.endObject();
            }
            json.endObject();
        }
        write("performance.json", json.endArray().endObject().toString());
    }

    /** Writes changed detail files and deletes those of players no longer online. */
    void writePlayers(String meta, List<Snapshot.Player> players) {
        Set<String> online = new HashSet<>();
        for (Snapshot.Player p : players) {
            online.add(p.uuid);
            String body = playerBody(p);
            Path file = playersDir.resolve(p.uuid + ".json");
            if (body.equals(playerBodies.get(p.uuid)) && Files.exists(file)) {
                continue;
            }
            if (write(file, "{\"bridge\":" + meta + "," + body + "}")) {
                playerBodies.put(p.uuid, body);
            }
        }
        playerBodies.keySet().retainAll(online);
        deleteStalePlayers(online);
    }

    /** Clean shutdown: no player is online any more. */
    void clearPlayers() {
        playerBodies.clear();
        deleteStalePlayers(Set.of());
    }

    private static String playerBody(Snapshot.Player p) {
        Json json = new Json().beginObject()
                .field("uuid", p.uuid)
                .field("name", p.name)
                .name("inventory");
        items(json, p.inventory);
        json.name("armor");
        if (p.equipmentKnown) {
            json.beginObject();
            item(json.name("head"), p.head);
            item(json.name("chest"), p.chest);
            item(json.name("legs"), p.legs);
            item(json.name("feet"), p.feet);
            json.endObject();
        } else {
            json.value((String) null);
        }
        item(json.name("offhand"), p.offhand);
        json.name("ender_chest");
        items(json, p.enderChest);
        json.name("effects");
        if (p.effects == null) {
            json.value((String) null);
        } else {
            json.beginArray();
            for (Snapshot.Effect e : p.effects) {
                json.beginObject()
                        .field("id", e.id)
                        .field("amplifier", e.amplifier)
                        .field("duration", e.duration)
                        .endObject();
            }
            json.endArray();
        }
        String object = json.endObject().toString();
        return object.substring(1, object.length() - 1); // the members, spliced after the meta block
    }

    private static void items(Json json, List<Snapshot.Item> items) {
        if (items == null) {
            json.value((String) null);
            return;
        }
        json.beginArray();
        for (Snapshot.Item item : items) {
            item(json, item);
        }
        json.endArray();
    }

    private static void item(Json json, Snapshot.Item item) {
        if (item == null) {
            json.value((String) null);
            return;
        }
        json.beginObject();
        if (item.slot >= 0) {
            json.field("slot", item.slot);
        }
        json.field("id", item.id)
                .field("count", item.count)
                .field("name", item.name)
                .field("enchanted", item.enchanted)
                .field("damage", item.damage)
                .field("max_damage", item.maxDamage)
                .endObject();
    }

    // ------------------------------------------------------------------ files

    private boolean write(String name, String json) {
        return write(dir.resolve(name), json);
    }

    /** Atomic replace; false (logged once per file) when the disk refuses. */
    private boolean write(Path target, String json) {
        Path tmp = target.resolveSibling(target.getFileName() + ".tmp");
        try {
            Files.createDirectories(target.getParent());
            Files.write(tmp, json.getBytes(StandardCharsets.UTF_8));
            try {
                Files.move(tmp, target, StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING);
            } catch (IOException atomicFailed) {
                Files.move(tmp, target, StandardCopyOption.REPLACE_EXISTING);
            }
            return true;
        } catch (IOException | RuntimeException e) {
            Log.warnOnce("write:" + target.getFileName(), "Could not write " + target + " (" + e + ")", null);
            try {
                Files.deleteIfExists(tmp);
            } catch (IOException ignored) {
                // nothing more to do
            }
            return false;
        }
    }

    private void deleteStalePlayers(Set<String> online) {
        if (!Files.isDirectory(playersDir)) {
            return;
        }
        try (DirectoryStream<Path> files = Files.newDirectoryStream(playersDir)) {
            for (Path file : files) {
                String name = file.getFileName().toString();
                boolean stale = name.endsWith(".tmp")
                        || (PLAYER_FILE.matcher(name).matches() && !online.contains(name.substring(0, name.length() - 5)));
                if (stale) {
                    Files.deleteIfExists(file);
                }
            }
        } catch (IOException | RuntimeException e) {
            Log.warnOnce("prune", "Could not clean up " + playersDir + " (" + e + ")", null);
        }
    }
}
