package io.github.palmeany.mctoolkit.bridge;

import java.util.LinkedHashMap;
import java.util.Map;
import java.util.UUID;

/**
 * Everything the bridge knows about one online player at one moment, read on the thread that owns the player and
 * handed to the writer as plain data. Fields the platform cannot provide stay null (and are written as null).
 */
final class PlayerSnapshot {

    final UUID uuid;
    final String name;
    String mode;
    String world;
    String dimension;
    Double x;
    Double y;
    Double z;
    Double health;
    Double maxHealth;
    Integer food;
    Double saturation;
    Integer level;
    Double exp;
    Integer ping;
    Boolean op;
    Long joinedAt;
    /** Body of the per-player file without the meta block, null when the detail was not read. */
    Map<String, Object> detail;

    PlayerSnapshot(UUID uuid, String name) {
        this.uuid = uuid;
        this.name = name;
    }

    /** Dashed lowercase UUID, the key the panel uses everywhere. */
    String id() {
        return uuid.toString().toLowerCase(java.util.Locale.ROOT);
    }

    /** The player's entry in {@code state.json} (keyed by {@link #id()} there). */
    Map<String, Object> state() {
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("name", name);
        out.put("mode", mode);
        out.put("world", world);
        out.put("dimension", dimension);
        out.put("x", Json.round(x));
        out.put("y", Json.round(y));
        out.put("z", Json.round(z));
        out.put("health", Json.round(health));
        out.put("max_health", Json.round(maxHealth));
        out.put("food", food);
        out.put("saturation", Json.round(saturation));
        out.put("level", level);
        out.put("exp", Json.round(exp));
        out.put("ping", ping);
        out.put("op", op);
        out.put("joined_at", joinedAt);
        return out;
    }

    /** The player's pin in {@code map.json}. */
    Map<String, Object> pin() {
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("uuid", id());
        out.put("name", name);
        out.put("world", world);
        out.put("dimension", dimension);
        out.put("x", Json.round(x));
        out.put("y", Json.round(y));
        out.put("z", Json.round(z));
        return out;
    }
}
