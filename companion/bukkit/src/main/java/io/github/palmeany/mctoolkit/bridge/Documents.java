package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Builds the JSON documents of protocol v1 (see companion/PROTOCOL.md) from a {@link Cycle}. */
final class Documents {

    static final int PROTOCOL = 1;
    static final int INTERVAL_SECONDS = 5;
    static final double MAX_TPS = 20.0;

    private final String version;
    private final String platform;
    private final String software;
    private final String minecraft;

    Documents(String version, String platform, String software, String minecraft) {
        this.version = version;
        this.platform = platform;
        this.software = software;
        this.minecraft = minecraft;
    }

    /** The {@code bridge} meta block present in every file. */
    Map<String, Object> meta(long updatedAt, boolean running) {
        Map<String, Object> meta = new LinkedHashMap<>();
        meta.put("protocol", PROTOCOL);
        meta.put("version", version);
        meta.put("platform", platform);
        meta.put("software", software);
        meta.put("minecraft", minecraft);
        meta.put("updated_at", updatedAt);
        meta.put("interval", INTERVAL_SECONDS);
        meta.put("running", running);
        return meta;
    }

    String state(Map<String, Object> meta, List<PlayerSnapshot> players) {
        Map<String, Object> byId = new LinkedHashMap<>();
        for (PlayerSnapshot player : players) {
            byId.put(player.id(), player.state());
        }
        Map<String, Object> document = new LinkedHashMap<>();
        document.put("bridge", meta);
        document.put("players", byId);
        return Json.write(document);
    }

    String map(Map<String, Object> meta, Long time, List<PlayerSnapshot> players) {
        List<Object> pins = new ArrayList<>(players.size());
        for (PlayerSnapshot player : players) {
            pins.add(player.pin());
        }
        Map<String, Object> document = new LinkedHashMap<>();
        document.put("bridge", meta);
        document.put("time", time);
        document.put("players", pins);
        return Json.write(document);
    }

    String performance(Map<String, Object> meta, Cycle cycle, List<PlayerSnapshot> players) {
        Map<String, Integer> playersPerWorld = new HashMap<>();
        for (PlayerSnapshot player : players) {
            if (player.world != null) {
                Integer before = playersPerWorld.get(player.world);
                playersPerWorld.put(player.world, before == null ? 1 : before + 1);
            }
        }
        Map<String, Object> averages = new LinkedHashMap<>();
        averages.put("one", tps(cycle.tpsOne));
        averages.put("five", tps(cycle.tpsFive));
        averages.put("fifteen", tps(cycle.tpsFifteen));

        List<Object> worlds = new ArrayList<>();
        List<Worlds.Stats> stats;
        synchronized (cycle.worlds) {
            stats = new ArrayList<>(cycle.worlds);
        }
        for (Worlds.Stats world : stats) {
            Map<String, Object> row = new LinkedHashMap<>();
            row.put("name", world.name);
            row.put("dimension", world.dimension);
            Integer count = playersPerWorld.get(world.name);
            row.put("players", count == null ? 0 : count);
            row.put("chunks", world.chunks);
            row.put("entity_count", world.entityCount);
            row.put("entities", world.entities);
            worlds.add(row);
        }

        Map<String, Object> document = new LinkedHashMap<>();
        document.put("bridge", meta);
        document.put("tps", tps(cycle.tps));
        document.put("mspt", Json.round(cycle.mspt()));
        document.put("tps_avg", averages);
        document.put("worlds", worlds);
        return Json.write(document);
    }

    /** {@code players/<uuid>.json} without the meta block; compared between cycles to skip unchanged rewrites. */
    String playerBody(PlayerSnapshot player) {
        Map<String, Object> body = player.detail;
        if (body == null) {
            body = new LinkedHashMap<>();
            body.put("uuid", player.id());
            body.put("name", player.name);
        }
        return Json.write(body);
    }

    /** A body from {@link #playerBody} with the meta block put in front. */
    static String withMeta(Map<String, Object> meta, String body) {
        StringBuilder out = new StringBuilder(body.length() + 200);
        out.append("{\"bridge\":");
        Json.append(out, meta);
        if (body.length() > 2) {
            out.append(',').append(body, 1, body.length());
        } else {
            out.append('}');
        }
        return out.toString();
    }

    private static Double tps(Double value) {
        Double rounded = Json.round(value);
        return rounded == null ? null : Math.max(0.0, Math.min(MAX_TPS, rounded));
    }
}
