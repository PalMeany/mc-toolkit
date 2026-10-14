package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.List;
import org.bukkit.plugin.Plugin;
import org.bukkit.scheduler.BukkitScheduler;
import org.bukkit.scheduler.BukkitTask;

/**
 * Spigot / Paper / Purpur / hybrids: a synchronous task collects every 5 seconds on the main thread and hands the
 * cycle to an asynchronous task that writes the files, so no disk IO happens on the main thread. Without Paper's
 * TPS API a second task feeds the {@link TpsMeter} every tick.
 */
final class BukkitDriver implements Driver {

    static final long FIRST_DELAY_TICKS = 20L;
    static final long PERIOD_TICKS = Documents.INTERVAL_SECONDS * 20L;

    private final Plugin plugin;
    private final Collector collector;
    private final BridgeWriter writer;
    private final TpsMeter meter;
    private final List<BukkitTask> tasks = new ArrayList<>();

    BukkitDriver(Plugin plugin, Collector collector, BridgeWriter writer, TpsMeter meter) {
        this.plugin = plugin;
        this.collector = collector;
        this.writer = writer;
        this.meter = meter;
    }

    @Override
    public void start() {
        BukkitScheduler scheduler = plugin.getServer().getScheduler();
        if (meter != null) {
            tasks.add(scheduler.runTaskTimer(plugin, () -> meter.tick(System.nanoTime()), 1L, 1L));
        }
        tasks.add(scheduler.runTaskTimer(plugin, this::cycle, FIRST_DELAY_TICKS, PERIOD_TICKS));
    }

    private void cycle() {
        try {
            Cycle cycle = collector.collectOnMainThread();
            if (cycle.claim()) {
                plugin.getServer().getScheduler().runTaskAsynchronously(plugin, () -> writer.write(cycle));
            }
        } catch (RuntimeException | LinkageError e) {
            collector.warn("Collecting live data failed", e);
        }
    }

    @Override
    public void stop() {
        for (BukkitTask task : tasks) {
            try {
                task.cancel();
            } catch (RuntimeException ignored) {
                // the server cancels a disabled plugin's tasks anyway
            }
        }
        tasks.clear();
    }

    @Override
    public String tpsSource() {
        return collector.platform().paperTps != null ? "Paper API" : "own tick counter";
    }

    @Override
    public String msptSource() {
        return collector.platform().paperMspt != null ? "Paper API" : "not available";
    }
}
