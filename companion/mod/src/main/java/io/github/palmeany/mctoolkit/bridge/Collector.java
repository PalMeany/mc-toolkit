package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Comparator;
import java.util.HashMap;
import java.util.IdentityHashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/** Copies game state into a {@link Snapshot}. Runs on the server thread only. */
final class Collector {

    private static final int MAIN_SLOTS = 36;
    private static final int FEET = 36;
    private static final int LEGS = 37;
    private static final int CHEST = 38;
    private static final int HEAD = 39;
    private static final int OFFHAND = 40;
    private static final int TOP_ENTITY_TYPES = 10;

    private final Mc mc;
    private final String folder;
    /** EntityType instance -> registry id; registries are frozen, so this stays small. */
    private final Map<Object, String> typeIds = new IdentityHashMap<>();

    Collector(Mc mc, String levelFolder) {
        this.mc = mc;
        this.folder = levelFolder;
    }

    Snapshot collect(Object server, int cycle) {
        Snapshot snap = new Snapshot();
        snap.mspt = mc.mspt(server);
        snap.dayTime = mc.dayTime(server);

        Map<Object, Snapshot.World> byLevel = new IdentityHashMap<>();
        for (Object level : mc.levels(server)) {
            try {
                Snapshot.World world = world(level);
                snap.worlds.add(world);
                byLevel.put(level, world);
            } catch (Throwable t) {
                Log.warnOnce("level", "Could not read a level; it is left out of performance.json", t);
            }
        }

        List<Object> players = mc.onlinePlayers(server);
        Bridge.Extension extension = Bridge.extension;
        if (extension != null) {
            players.addAll(extension.extraPlayers(server, cycle));
        }
        for (Object player : players) {
            try {
                Snapshot.Player data = player(server, player, byLevel);
                if (data != null) {
                    snap.players.add(data);
                }
            } catch (Throwable t) {
                Log.warnOnce("player", "Could not read a player; they are left out of this update", t);
            }
        }
        return snap;
    }

    // ------------------------------------------------------------------ worlds

    private Snapshot.World world(Object level) {
        Snapshot.World world = new Snapshot.World();
        String dim = mc.dimensionId(level);
        world.name = dim == null ? folder : worldName(folder, dim);
        world.dimension = dim == null ? null : dimensionLabel(dim);
        world.chunks = mc.loadedChunks(level);

        Iterable<?> entities = mc.entities(level);
        if (entities != null) {
            int total = 0;
            Map<String, Integer> counts = new HashMap<>();
            for (Object entity : entities) {
                if (entity == null) {
                    continue;
                }
                total++;
                String id = typeId(entity);
                if (id != null) {
                    counts.merge(id, 1, Integer::sum);
                }
            }
            world.entityCount = total;
            List<Map.Entry<String, Integer>> sorted = new ArrayList<>(counts.entrySet());
            sorted.sort(Map.Entry.<String, Integer>comparingByValue(Comparator.reverseOrder())
                    .thenComparing(Map.Entry.comparingByKey()));
            world.topEntities = new LinkedHashMap<>();
            for (Map.Entry<String, Integer> e : sorted.subList(0, Math.min(TOP_ENTITY_TYPES, sorted.size()))) {
                world.topEntities.put(e.getKey(), e.getValue());
            }
        }
        return world;
    }

    private String typeId(Object entity) {
        Object type = mc.entityType.get(entity);
        if (type == null) {
            return null;
        }
        String id = typeIds.get(type);
        if (id == null) {
            id = mc.entityTypeId(type);
            if (id != null) {
                typeIds.put(type, id);
            }
        }
        return id;
    }

    /**
     * Fabric/Forge keep every dimension inside one save folder; the bridge names levels the way
     * Bukkit does so the panel can tell them apart: {@code world}, {@code world_nether},
     * {@code world_the_end}, and {@code world_<namespace>_<path>} for modded dimensions.
     */
    static String worldName(String folder, String dimensionId) {
        switch (dimensionId) {
            case "minecraft:overworld": return folder;
            case "minecraft:the_nether": return folder + "_nether";
            case "minecraft:the_end": return folder + "_the_end";
            default: return folder + "_" + dimensionId.replace(':', '_').replace('/', '_');
        }
    }

    /** overworld | nether | end | namespace:path */
    static String dimensionLabel(String dimensionId) {
        switch (dimensionId) {
            case "minecraft:overworld": return "overworld";
            case "minecraft:the_nether": return "nether";
            case "minecraft:the_end": return "end";
            default: return dimensionId;
        }
    }

    // ------------------------------------------------------------------ players

    private Snapshot.Player player(Object server, Object player, Map<Object, Snapshot.World> worlds) {
        UUID id = mc.uuid(player);
        if (id == null) {
            return null;
        }
        Snapshot.Player out = new Snapshot.Player();
        out.uuid = id.toString();
        out.name = mc.name(player);
        out.mode = mc.gameMode(player);

        Object level = mc.level(player);
        Snapshot.World world = level == null ? null : worlds.get(level);
        if (world != null) {
            out.world = world.name;
            out.dimension = world.dimension;
            world.players++;
        } else {
            String dim = mc.dimensionId(level);
            if (dim != null) {
                out.world = worldName(folder, dim);
                out.dimension = dimensionLabel(dim);
            }
        }

        out.x = mc.x(player);
        out.y = mc.y(player);
        out.z = mc.z(player);
        out.health = mc.health(player);
        out.maxHealth = mc.maxHealth(player);
        out.food = mc.food(player);
        out.saturation = mc.saturation(player);
        out.level = mc.xpLevel(player);
        out.exp = mc.xpProgress(player);
        out.ping = mc.ping(player);
        out.op = mc.op(server, player);

        Object inventory = mc.inventory.get(player);
        Integer size = mc.containerSize(inventory);
        if (size != null) {
            out.inventory = new ArrayList<>();
            for (int slot = 0; slot < Math.min(size, MAIN_SLOTS); slot++) {
                Snapshot.Item item = mc.item(mc.containerItem(inventory, slot), slot);
                if (item != null) {
                    out.inventory.add(item);
                }
            }
            if (size > OFFHAND) {
                out.equipmentKnown = true;
                out.feet = mc.item(mc.containerItem(inventory, FEET), -1);
                out.legs = mc.item(mc.containerItem(inventory, LEGS), -1);
                out.chest = mc.item(mc.containerItem(inventory, CHEST), -1);
                out.head = mc.item(mc.containerItem(inventory, HEAD), -1);
                out.offhand = mc.item(mc.containerItem(inventory, OFFHAND), -1);
            }
        }

        Object ender = mc.enderChest.get(player);
        Integer enderSize = mc.containerSize(ender);
        if (enderSize != null) {
            out.enderChest = new ArrayList<>();
            for (int slot = 0; slot < enderSize; slot++) {
                Snapshot.Item item = mc.item(mc.containerItem(ender, slot), slot);
                if (item != null) {
                    out.enderChest.add(item);
                }
            }
        }

        Collection<?> effects = mc.effects(player);
        if (effects != null) {
            out.effects = new ArrayList<>();
            for (Object instance : effects) {
                Snapshot.Effect effect = mc.effect(instance);
                if (effect != null) {
                    out.effects.add(effect);
                }
            }
            out.effects.sort(Comparator.comparing(e -> e.id));
        }
        return out;
    }
}
