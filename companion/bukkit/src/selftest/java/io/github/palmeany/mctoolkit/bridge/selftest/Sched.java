package io.github.palmeany.mctoolkit.bridge.selftest;

import java.lang.reflect.Method;
import java.util.function.Consumer;
import org.bukkit.Bukkit;
import org.bukkit.Location;
import org.bukkit.plugin.Plugin;

/** Main-thread scheduling on Bukkit, global/region scheduling on Folia (reflection: compiled against 1.8.8). */
abstract class Sched {

    static boolean isFolia() {
        try {
            Class.forName("io.papermc.paper.threadedregions.RegionizedServer", false, Bukkit.getServer().getClass().getClassLoader());
            return true;
        } catch (ClassNotFoundException e) {
            return false;
        }
    }

    static Sched create(Plugin plugin) {
        return isFolia() ? new FoliaSched(plugin) : new BukkitSched(plugin);
    }

    /** {@code task} on the main thread (Folia: the global region thread) after {@code ticks}. */
    abstract void later(long ticks, Runnable task);

    /** {@code task} on the thread that owns {@code location}, after {@code ticks} (at least 1). */
    abstract void at(Location location, long ticks, Runnable task);

    private static final class BukkitSched extends Sched {
        private final Plugin plugin;

        BukkitSched(Plugin plugin) {
            this.plugin = plugin;
        }

        @Override
        void later(long ticks, Runnable task) {
            Bukkit.getScheduler().runTaskLater(plugin, task, Math.max(1, ticks));
        }

        @Override
        void at(Location location, long ticks, Runnable task) {
            later(ticks, task);
        }
    }

    private static final class FoliaSched extends Sched {
        private final Plugin plugin;
        private final Object global;
        private final Object regions;
        private final Method globalRunDelayed;
        private final Method regionRunDelayed;

        FoliaSched(Plugin plugin) {
            this.plugin = plugin;
            try {
                ClassLoader loader = Bukkit.getServer().getClass().getClassLoader();
                String pkg = "io.papermc.paper.threadedregions.scheduler.";
                Class<?> globalType = Class.forName(pkg + "GlobalRegionScheduler", false, loader);
                Class<?> regionType = Class.forName(pkg + "RegionScheduler", false, loader);
                global = Bukkit.getServer().getClass().getMethod("getGlobalRegionScheduler").invoke(Bukkit.getServer());
                regions = Bukkit.getServer().getClass().getMethod("getRegionScheduler").invoke(Bukkit.getServer());
                globalRunDelayed = globalType.getMethod("runDelayed", Plugin.class, Consumer.class, long.class);
                regionRunDelayed = regionType.getMethod("runDelayed", Plugin.class, Location.class, Consumer.class, long.class);
            } catch (ReflectiveOperationException e) {
                throw new IllegalStateException(e);
            }
        }

        @Override
        void later(long ticks, Runnable task) {
            Consumer<Object> body = scheduled -> task.run();
            try {
                globalRunDelayed.invoke(global, plugin, body, Math.max(1, ticks));
            } catch (ReflectiveOperationException e) {
                throw new IllegalStateException(e);
            }
        }

        @Override
        void at(Location location, long ticks, Runnable task) {
            Consumer<Object> body = scheduled -> task.run();
            try {
                regionRunDelayed.invoke(regions, plugin, location, body, Math.max(1, ticks));
            } catch (ReflectiveOperationException e) {
                throw new IllegalStateException(e);
            }
        }
    }
}
