package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.List;
import java.util.UUID;
import org.bukkit.Location;
import org.bukkit.Server;
import org.bukkit.World;
import org.bukkit.entity.Player;

/**
 * Folia has no main thread. Every 5 seconds a task on the global region thread starts a cycle with what is safe to
 * read there (world list, thread-safe chunk/entity counters, region TPS, day time), asks each player's own region
 * thread for that player's data (EntityScheduler) and the region holding each world's spawn for its MSPT; the
 * cycle goes to the async writer once every piece is in, or at the latest when the next cycle starts.
 */
final class FoliaDriver implements Driver {

    private final Collector collector;
    private final BridgeWriter writer;
    private final FoliaScheduler scheduler;
    private final Method regionTps;
    private volatile Cycle current;
    private volatile boolean stopped;

    FoliaDriver(Collector collector, BridgeWriter writer, FoliaScheduler scheduler) {
        this.collector = collector;
        this.writer = writer;
        this.scheduler = scheduler;
        this.regionTps = Reflect.method(Server.class, "getRegionTPS", Location.class);
    }

    @Override
    public void start() {
        scheduler.runRepeating(this::cycle, BukkitDriver.FIRST_DELAY_TICKS, BukkitDriver.PERIOD_TICKS);
    }

    /** Runs on the global region thread. */
    private void cycle() {
        if (stopped) {
            return;
        }
        try {
            Cycle previous = current;
            if (previous != null) {
                submit(previous); // a region that never answered must not hold the files back
            }
            Cycle cycle = collector.newCycle();
            current = cycle;
            Server server = collector.server();
            Platform platform = collector.platform();
            List<World> worlds = server.getWorlds();

            double[] worst = null;
            for (World world : worlds) {
                try {
                    cycle.worlds.add(Worlds.readConcurrent(world));
                } catch (RuntimeException | LinkageError e) {
                    collector.warn("Cannot read world " + world.getName(), e);
                }
                Location spawn = PlayerReader.safe(world::getSpawnLocation);
                // [5s, 15s, 1m, 5m, 15m] of the region at the spawn; null when no region holds that chunk
                double[] tps = spawn == null ? null : asRegionTps(Reflect.invoke(regionTps, server, spawn));
                if (tps != null) {
                    worst = worst(worst, tps);
                    Runnable done = cycle.fork();
                    boolean scheduled = scheduler.runAt(spawn, () -> {
                        try {
                            // inside a region task Paper's getAverageTickTime() answers for that region
                            cycle.offerMspt(Collector.asDouble(Reflect.invoke(platform.paperMspt, server)));
                        } finally {
                            done.run();
                        }
                    });
                    if (!scheduled) {
                        done.run();
                    }
                }
            }
            if (worst != null) {
                cycle.tps = worst[0];
                cycle.tpsOne = worst[2];
                cycle.tpsFive = worst[3];
                cycle.tpsFifteen = worst[4];
            } else {
                // no spawn chunk is loaded anywhere: report the global region (this thread), which always ticks
                double[] global = Collector.asTps(Reflect.invoke(platform.paperTps, server));
                if (global != null) {
                    cycle.tps = global[0];
                    cycle.tpsOne = global[0];
                    cycle.tpsFive = global[1];
                    cycle.tpsFifteen = global[2];
                }
                cycle.offerMspt(Collector.asDouble(Reflect.invoke(platform.paperMspt, server)));
            }
            cycle.time = collector.overworldTime(worlds);

            for (Player player : collector.players()) {
                UUID id = player.getUniqueId();
                Long joinedAt = collector.joinedAt(id);
                cycle.expect(id);
                Runnable done = cycle.fork();
                boolean scheduled = scheduler.runOn(player, () -> {
                    try {
                        cycle.put(PlayerReader.read(player, joinedAt));
                    } catch (RuntimeException | LinkageError e) {
                        collector.warn("Cannot read player " + player.getName(), e);
                    } finally {
                        done.run();
                    }
                }, () -> {
                    cycle.retire(id);
                    done.run();
                });
                if (!scheduled) {
                    cycle.retire(id);
                    done.run();
                }
            }
            cycle.arm(() -> submit(cycle));
        } catch (RuntimeException | LinkageError e) {
            collector.warn("Collecting live data failed", e);
        }
    }

    private void submit(Cycle cycle) {
        if (!stopped && cycle.claim()) {
            try {
                scheduler.runAsync(() -> writer.write(cycle));
            } catch (RuntimeException e) {
                collector.warn("Cannot schedule the file write", e);
            }
        }
    }

    private static double[] asRegionTps(Object value) {
        return value instanceof double[] && ((double[]) value).length >= 5 ? (double[]) value : null;
    }

    private static double[] worst(double[] worst, double[] candidate) {
        if (worst == null) {
            return candidate.clone();
        }
        for (int i = 0; i < worst.length && i < candidate.length; i++) {
            worst[i] = Math.min(worst[i], candidate[i]);
        }
        return worst;
    }

    @Override
    public void stop() {
        stopped = true;
        scheduler.cancelAll();
    }

    @Override
    public String tpsSource() {
        return regionTps != null ? "Folia region API" : "not available";
    }

    @Override
    public String msptSource() {
        return collector.platform().paperMspt != null ? "Folia region API" : "not available";
    }
}
