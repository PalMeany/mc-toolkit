package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.bukkit.World;
import org.bukkit.entity.Entity;

/** Per-world numbers for {@code performance.json} and the dimension names used in every file. */
final class Worlds {

    static final int TOP_ENTITY_TYPES = 10;

    private Worlds() {
    }

    /** One row of {@code performance.json#worlds}; the player count is filled in from the player snapshots. */
    static final class Stats {
        final String name;
        final String dimension;
        Integer chunks;
        Integer entityCount;
        /** At most {@link #TOP_ENTITY_TYPES} most common types, null when entities cannot be listed safely. */
        Map<String, Integer> entities;

        Stats(String name, String dimension) {
            this.name = name;
            this.dimension = dimension;
        }
    }

    /** overworld | nether | end by environment, otherwise the world's key ({@code namespace:path}) if it has one. */
    static String dimension(World world) {
        Object environment = world.getEnvironment();
        String name = environment == null ? "" : environment.toString();
        switch (name) {
            case "NORMAL":
                return "overworld";
            case "NETHER":
                return "nether";
            case "THE_END":
                return "end";
            default:
                String key = Ids.keyOf(world); // World#getKey on Paper 1.16.5+ and modern Spigot
                return key != null ? key : (name.isEmpty() ? "unknown" : Ids.lower(name));
        }
    }

    /**
     * Full numbers, read on the thread that owns the world (the main thread on Spigot/Paper): loaded chunks, every
     * entity counted by type.
     */
    static Stats read(World world) {
        Stats stats = new Stats(world.getName(), dimension(world));
        stats.chunks = chunkCount(world, true);
        try {
            List<Entity> entities = world.getEntities();
            Map<Object, int[]> byType = new HashMap<>();
            for (Entity entity : entities) {
                Object type = entity.getType();
                int[] count = byType.get(type);
                if (count == null) {
                    byType.put(type, count = new int[1]);
                }
                count[0]++;
            }
            Map<String, Integer> byId = new HashMap<>();
            for (Map.Entry<Object, int[]> entry : byType.entrySet()) {
                String id = Ids.entity(entry.getKey());
                Integer before = byId.get(id);
                byId.put(id, (before == null ? 0 : before) + entry.getValue()[0]);
            }
            stats.entityCount = entities.size();
            stats.entities = top(byId, TOP_ENTITY_TYPES);
        } catch (RuntimeException | LinkageError e) {
            stats.entityCount = null;
            stats.entities = null;
        }
        return stats;
    }

    /**
     * Folia: only what can be read safely from the global region thread. Chunk and entity totals come from Paper's
     * thread-safe counters; listing entities by type would touch other regions' entities, so it stays null.
     */
    static Stats readConcurrent(World world) {
        Stats stats = new Stats(world.getName(), dimension(world));
        stats.chunks = chunkCount(world, false);
        Object total = Reflect.call(world, World.class, "getEntityCount");
        stats.entityCount = total instanceof Number ? ((Number) total).intValue() : null;
        stats.entities = null;
        return stats;
    }

    private static Integer chunkCount(World world, boolean ownsWorld) {
        Method count = Reflect.method(World.class, "getChunkCount"); // Paper
        if (count != null) {
            Object value = Reflect.invoke(count, world);
            if (value instanceof Number) {
                return ((Number) value).intValue();
            }
        }
        if (!ownsWorld) {
            return null;
        }
        try {
            return world.getLoadedChunks().length;
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
    }

    /** The {@code limit} largest counts, biggest first (ties by id), as an insertion-ordered map. */
    static Map<String, Integer> top(Map<String, Integer> counts, int limit) {
        List<Map.Entry<String, Integer>> entries = new ArrayList<>(counts.entrySet());
        entries.sort((a, b) -> {
            int byCount = Integer.compare(b.getValue(), a.getValue());
            return byCount != 0 ? byCount : a.getKey().compareTo(b.getKey());
        });
        Map<String, Integer> top = new LinkedHashMap<>();
        for (int i = 0; i < entries.size() && i < limit; i++) {
            top.put(entries.get(i).getKey(), entries.get(i).getValue());
        }
        return top;
    }
}
