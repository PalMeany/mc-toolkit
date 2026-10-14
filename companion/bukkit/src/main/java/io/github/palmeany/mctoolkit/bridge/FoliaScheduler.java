package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.function.Consumer;
import org.bukkit.Location;
import org.bukkit.Server;
import org.bukkit.entity.Entity;
import org.bukkit.plugin.Plugin;

/**
 * Folia's (and Paper 1.20+'s) region-aware schedulers, reached through reflection so the jar still compiles against
 * and loads on 1.8.8. Only used on Folia; Paper keeps the classic main-thread scheduler.
 */
final class FoliaScheduler {

    private static final String PACKAGE = "io.papermc.paper.threadedregions.scheduler.";

    private final Plugin plugin;
    private final Object global;
    private final Object async;
    private final Object regions;
    private final Method globalRunAtFixedRate;
    private final Method globalCancel;
    private final Method asyncRunNow;
    private final Method asyncCancel;
    private final Method regionExecute;
    private final Method entityScheduler;
    private final Method entityExecute;

    private FoliaScheduler(Plugin plugin) {
        this.plugin = plugin;
        Server server = plugin.getServer();
        Class<?> globalType = Reflect.type(PACKAGE + "GlobalRegionScheduler");
        Class<?> asyncType = Reflect.type(PACKAGE + "AsyncScheduler");
        Class<?> regionType = Reflect.type(PACKAGE + "RegionScheduler");
        Class<?> entityType = Reflect.type(PACKAGE + "EntityScheduler");
        global = require(Reflect.call(server, Server.class, "getGlobalRegionScheduler"), "getGlobalRegionScheduler");
        async = require(Reflect.call(server, Server.class, "getAsyncScheduler"), "getAsyncScheduler");
        regions = require(Reflect.call(server, Server.class, "getRegionScheduler"), "getRegionScheduler");
        globalRunAtFixedRate = require(Reflect.method(globalType, "runAtFixedRate", Plugin.class, Consumer.class,
                long.class, long.class), "GlobalRegionScheduler#runAtFixedRate");
        globalCancel = require(Reflect.method(globalType, "cancelTasks", Plugin.class), "GlobalRegionScheduler#cancelTasks");
        asyncRunNow = require(Reflect.method(asyncType, "runNow", Plugin.class, Consumer.class), "AsyncScheduler#runNow");
        asyncCancel = require(Reflect.method(asyncType, "cancelTasks", Plugin.class), "AsyncScheduler#cancelTasks");
        regionExecute = require(Reflect.method(regionType, "execute", Plugin.class, Location.class, Runnable.class),
                "RegionScheduler#execute");
        entityScheduler = require(Reflect.method(Entity.class, "getScheduler"), "Entity#getScheduler");
        entityExecute = require(Reflect.method(entityType, "execute", Plugin.class, Runnable.class, Runnable.class,
                long.class), "EntityScheduler#execute");
    }

    static FoliaScheduler create(Plugin plugin) {
        return new FoliaScheduler(plugin);
    }

    /** Repeating task on the global region thread. */
    void runRepeating(Runnable task, long delayTicks, long periodTicks) {
        Consumer<Object> body = scheduled -> task.run();
        invoke(globalRunAtFixedRate, global, plugin, body, delayTicks, periodTicks);
    }

    /** Task on Folia's async pool (disk IO). */
    void runAsync(Runnable task) {
        Consumer<Object> body = scheduled -> task.run();
        invoke(asyncRunNow, async, plugin, body);
    }

    /** Task on the region that owns {@code location}; false when it could not be scheduled. */
    boolean runAt(Location location, Runnable task) {
        try {
            regionExecute.invoke(regions, plugin, location, task);
            return true;
        } catch (Exception | LinkageError e) {
            return false;
        }
    }

    /**
     * Task on the thread that owns {@code entity}, next tick. {@code retired} runs instead if the entity is removed
     * (player logged out) first. False when the entity is already gone and neither will run.
     */
    boolean runOn(Entity entity, Runnable task, Runnable retired) {
        try {
            Object scheduler = entityScheduler.invoke(entity);
            return Boolean.TRUE.equals(entityExecute.invoke(scheduler, plugin, task, retired, 1L));
        } catch (Exception | LinkageError e) {
            return false;
        }
    }

    void cancelAll() {
        Reflect.invoke(globalCancel, global, plugin);
        Reflect.invoke(asyncCancel, async, plugin);
    }

    private static void invoke(Method method, Object target, Object... arguments) {
        try {
            method.invoke(target, arguments);
        } catch (Exception | LinkageError e) {
            throw new IllegalStateException("Folia scheduler call " + method.getName() + " failed", e);
        }
    }

    private static <T> T require(T value, String what) {
        if (value == null) {
            throw new IllegalStateException("Folia scheduler API not found: " + what);
        }
        return value;
    }
}
