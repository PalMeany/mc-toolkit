package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicLong;
import java.util.function.Supplier;
import java.util.logging.Level;
import java.util.logging.Logger;
import org.bukkit.Server;
import org.bukkit.World;
import org.bukkit.entity.Player;

/** Reads the server into cycles. The thread rules are the drivers' business; this class only knows what to read. */
final class Collector {

    private static final long WARN_EVERY_MILLIS = 60_000L;

    private final Server server;
    private final Platform platform;
    private final TpsMeter meter;
    private final Map<UUID, Long> joinedAt;
    private final List<Supplier<? extends Collection<? extends Player>>> extraPlayers;
    private final Logger logger;
    private final AtomicLong sequence = new AtomicLong();
    private volatile long lastWarning;

    Collector(Server server, Platform platform, TpsMeter meter, Map<UUID, Long> joinedAt,
            List<Supplier<? extends Collection<? extends Player>>> extraPlayers, Logger logger) {
        this.server = server;
        this.platform = platform;
        this.meter = meter;
        this.joinedAt = joinedAt;
        this.extraPlayers = extraPlayers;
        this.logger = logger;
    }

    Server server() {
        return server;
    }

    Platform platform() {
        return platform;
    }

    Cycle newCycle() {
        return new Cycle(sequence.incrementAndGet());
    }

    /** Online players plus any added by a development player source, one entry per UUID. */
    List<Player> players() {
        Map<UUID, Player> players = new LinkedHashMap<>();
        for (Player player : server.getOnlinePlayers()) {
            players.put(player.getUniqueId(), player);
        }
        for (Supplier<? extends Collection<? extends Player>> source : extraPlayers) {
            try {
                Collection<? extends Player> extra = source.get();
                if (extra != null) {
                    for (Player player : extra) {
                        players.put(player.getUniqueId(), player);
                    }
                }
            } catch (RuntimeException e) {
                warn("A player source failed", e);
            }
        }
        return new ArrayList<>(players.values());
    }

    Long joinedAt(UUID player) {
        return joinedAt.get(player);
    }

    /** Spigot/Paper: the whole cycle, read on the main thread. */
    Cycle collectOnMainThread() {
        Cycle cycle = newCycle();
        double[] paper = asTps(Reflect.invoke(platform.paperTps, server));
        if (paper != null) {
            // Paper: 1m, 5m, 15m averages; the 1-minute one is the most recent figure it exposes
            cycle.tps = paper[0];
            cycle.tpsOne = paper[0];
            cycle.tpsFive = paper[1];
            cycle.tpsFifteen = paper[2];
        } else if (meter != null) {
            cycle.tps = meter.average(TpsMeter.LATEST);
            cycle.tpsOne = meter.average(TpsMeter.ONE_MINUTE);
            cycle.tpsFive = meter.average(TpsMeter.FIVE_MINUTES);
            cycle.tpsFifteen = meter.average(TpsMeter.FIFTEEN_MINUTES);
        }
        cycle.mspt(asDouble(Reflect.invoke(platform.paperMspt, server)));

        List<World> worlds = server.getWorlds();
        for (World world : worlds) {
            try {
                cycle.worlds.add(Worlds.read(world));
            } catch (RuntimeException | LinkageError e) {
                warn("Cannot read world " + world.getName(), e);
            }
        }
        cycle.time = overworldTime(worlds);

        for (Player player : players()) {
            try {
                cycle.put(PlayerReader.read(player, joinedAt(player.getUniqueId())));
            } catch (RuntimeException | LinkageError e) {
                warn("Cannot read player " + player.getName(), e);
            }
        }
        return cycle;
    }

    /** Day time (0..23999) of the first overworld-type world, else of the main world. */
    Long overworldTime(List<World> worlds) {
        World overworld = null;
        for (World world : worlds) {
            if ("NORMAL".equals(String.valueOf(world.getEnvironment()))) {
                overworld = world;
                break;
            }
        }
        if (overworld == null && !worlds.isEmpty()) {
            overworld = worlds.get(0);
        }
        if (overworld == null) {
            return null;
        }
        try {
            long time = overworld.getTime() % 24000L;
            return time < 0 ? time + 24000L : time;
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
    }

    static double[] asTps(Object value) {
        return value instanceof double[] && ((double[]) value).length >= 3 ? (double[]) value : null;
    }

    static Double asDouble(Object value) {
        return value instanceof Number ? ((Number) value).doubleValue() : null;
    }

    /** At most one warning per minute, so a persistent failure does not flood the console every 5 seconds. */
    void warn(String message, Throwable error) {
        long now = System.currentTimeMillis();
        if (now - lastWarning < WARN_EVERY_MILLIS) {
            return;
        }
        lastWarning = now;
        logger.log(Level.WARNING, message, error);
    }
}
