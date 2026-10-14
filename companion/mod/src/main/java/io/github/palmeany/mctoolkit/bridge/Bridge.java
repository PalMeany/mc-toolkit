package io.github.palmeany.mctoolkit.bridge;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Properties;
import java.util.Set;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.Executor;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * The bridge itself, shared by every loader: a daemon thread that finds the running server, hops
 * onto the server thread every 5 seconds (through {@link Executor#execute}, a JDK method whose name
 * no mapping touches) to copy the game state, and writes the protocol files from its own thread.
 * It registers nothing in the game: no events, commands, network channels or config.
 */
public final class Bridge {

    public static final String MOD_ID = "mctoolkit_bridge";
    static final int INTERVAL_SECONDS = 5;
    static final String VERSION = readVersion();

    private static final long INTERVAL_NANOS = TimeUnit.SECONDS.toNanos(INTERVAL_SECONDS);
    private static final long SERVER_THREAD_TIMEOUT_MS = 4_000;
    private static final AtomicBoolean STARTED = new AtomicBoolean();

    /** Development hook used by the self-test jar; always null in the release jar. */
    interface Extension {
        /** Called on the server thread at every cycle; returns extra (client-less) players. */
        List<Object> extraPlayers(Object server, int cycle);
    }

    static volatile Extension extension;

    private final Platform platform;
    private final Object writeLock = new Object();
    private final Tps tps = new Tps();
    private final Map<String, Long> joinedAt = new HashMap<>();

    private volatile boolean closed;
    private volatile Output output;
    private String software;
    private String minecraft;
    private Mc mc;
    private Collector collector;
    private Thread serverThread;
    private CompletableFuture<Snapshot> pending;
    private int cycle;

    // last values, reused by the final write and when the server thread does not answer in time
    private List<Snapshot.World> lastWorlds = List.of();
    private Long lastDayTime;
    private Double lastMspt;
    private Double[] lastTps = new Double[4];

    private Bridge(Platform platform) {
        this.platform = platform;
    }

    /** Starts the bridge once per JVM; must only be called on a dedicated server. Never throws. */
    static void start(Platform platform) {
        if (!STARTED.compareAndSet(false, true)) {
            return;
        }
        try {
            Bridge bridge = new Bridge(platform);
            Thread worker = new Thread(bridge::run, "MCToolkit-Bridge");
            worker.setDaemon(true);
            worker.setPriority(Thread.MIN_PRIORITY);
            worker.start();
            Runtime.getRuntime().addShutdownHook(new Thread(() -> bridge.finish("JVM shutdown"), "MCToolkit-Bridge-Shutdown"));
        } catch (Throwable t) {
            Log.warn("MC Toolkit Bridge could not start", t);
        }
    }

    // ------------------------------------------------------------------ worker thread

    private void run() {
        try {
            Object server = awaitServer();
            Names names = Names.detect(server.getClass());
            mc = new Mc(names);
            serverThread = mc.serverThread(server);
            software = platform.software();
            minecraft = mc.minecraftVersion(server);
            String folder = levelFolder(server);
            collector = new Collector(mc, folder);
            Path dir = bridgeDir();
            logSummary(names, dir);
            loadExtension(names);

            if (!awaitReady(server)) {
                finish("server stopped during startup");
                return;
            }
            output = new Output(dir);
            sampleTicks(server);
            long next = System.nanoTime() + INTERVAL_NANOS;
            while (true) {
                if (!alive(server)) {
                    finish("server stopped");
                    return;
                }
                long wait = next - System.nanoTime();
                if (wait > 0) {
                    Thread.sleep(Math.min(250, TimeUnit.NANOSECONDS.toMillis(wait) + 1));
                    continue;
                }
                try {
                    cycle(server);
                } catch (RuntimeException | LinkageError e) {
                    Log.warnOnce("cycle", "An update cycle failed; the bridge keeps going (" + e + ")", e);
                }
                next += INTERVAL_NANOS;
                if (next < System.nanoTime()) {
                    next = System.nanoTime() + INTERVAL_NANOS; // fell behind (suspended JVM): no burst
                }
            }
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        } catch (Throwable t) {
            Log.warn("MC Toolkit Bridge stopped after an unexpected error; the server is not affected", t);
        }
    }

    private Object awaitServer() throws InterruptedException {
        long since = System.nanoTime();
        boolean told = false;
        while (true) {
            Object server = platform.server();
            if (server != null) {
                return server;
            }
            if (!told && System.nanoTime() - since > TimeUnit.MINUTES.toNanos(10)) {
                Log.info("Still waiting for " + platform.id + " to report a running server");
                told = true;
            }
            Thread.sleep(500);
        }
    }

    /** Waits until the server ticks (worlds loaded); false when it stopped first. */
    private boolean awaitReady(Object server) throws InterruptedException {
        while (true) {
            if (!alive(server)) {
                return false;
            }
            Integer ticks = mc.tickCount(server);
            if (Boolean.TRUE.equals(mc.isReady(server)) || (ticks != null && ticks > 0)) {
                return true;
            }
            Thread.sleep(250);
        }
    }

    private boolean alive(Object server) {
        if (!mc.isRunning(server)) {
            return false;
        }
        Thread thread = serverThread;
        return thread == null || thread.isAlive();
    }

    private void sampleTicks(Object server) {
        Integer ticks = mc.tickCount(server); // plain int reads; safe off-thread
        if (ticks != null) {
            tps.add(System.nanoTime(), ticks, mc.pausedWhenEmpty(server));
        }
    }

    private void cycle(Object server) {
        cycle++;
        sampleTicks(server);
        Snapshot snap = collect(server);
        long now = System.currentTimeMillis() / 1000L;
        synchronized (writeLock) {
            if (closed) {
                return;
            }
            Double mspt;
            if (snap != null) {
                trackJoins(snap.players, now);
                lastWorlds = snap.worlds;
                lastDayTime = snap.dayTime;
                mspt = snap.mspt;
            } else {
                mspt = mc.mspt(server); // the server thread is stuck: read what we can from here
            }
            lastMspt = mspt;
            lastTps = new Double[] {tps.latest(), tps.average(Tps.MINUTE), tps.average(5 * Tps.MINUTE), tps.average(15 * Tps.MINUTE)};
            String meta = meta(true, now);
            output.writePerformance(meta, lastTps[0], lastTps[1], lastTps[2], lastTps[3], mspt, lastWorlds);
            if (snap != null) {
                output.writeState(meta, snap.players, joinedAt);
                output.writeMap(meta, snap.dayTime, snap.players);
                output.writePlayers(meta, snap.players);
            }
        }
    }

    /**
     * Copies the game state on the server thread. A stuck server thread is waited for at most 4 s;
     * the task is not resubmitted while one is still queued.
     */
    private Snapshot collect(Object server) {
        CompletableFuture<Snapshot> future = pending;
        if (future == null || future.isDone()) {
            CompletableFuture<Snapshot> task = new CompletableFuture<>();
            int number = cycle;
            Thread expected = serverThread;
            ((Executor) server).execute(() -> {
                if (expected != null && Thread.currentThread() != expected) {
                    task.complete(null); // a stopped server runs tasks inline on the caller: never collect off-thread
                    return;
                }
                try {
                    task.complete(collector.collect(server, number));
                } catch (Throwable t) {
                    task.completeExceptionally(t);
                }
            });
            pending = future = task;
        }
        try {
            return future.get(SERVER_THREAD_TIMEOUT_MS, TimeUnit.MILLISECONDS);
        } catch (TimeoutException e) {
            Log.warnOnce("timeout", "The server thread did not answer within 4 s (lagging?); player data is kept until it does", null);
            return null;
        } catch (ExecutionException e) {
            Log.warnOnce("collect", "Collecting game data failed; retrying every cycle", e.getCause());
            return null;
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return null;
        }
    }

    /** joined_at = first cycle a player was seen online (at most one interval late). */
    private void trackJoins(List<Snapshot.Player> players, long now) {
        Set<String> online = new HashSet<>();
        for (Snapshot.Player p : players) {
            online.add(p.uuid);
            joinedAt.putIfAbsent(p.uuid, now);
        }
        joinedAt.keySet().retainAll(online);
    }

    /** Final write: no players, running=false. Runs once, from the worker or the JVM shutdown hook. */
    private void finish(String reason) {
        synchronized (writeLock) {
            if (closed) {
                return;
            }
            closed = true;
            Output out = output;
            if (out == null) {
                return;
            }
            String meta = meta(false, System.currentTimeMillis() / 1000L);
            out.writeState(meta, List.of(), Map.of());
            out.writeMap(meta, lastDayTime, List.of());
            out.writePerformance(meta, lastTps[0], lastTps[1], lastTps[2], lastTps[3], lastMspt, lastWorlds);
            out.clearPlayers();
        }
        try {
            Log.info("Wrote the final state (running=false): " + reason);
        } catch (Throwable ignored) {
            // logging may already be shut down
        }
    }

    private String meta(boolean running, long now) {
        return new Json().beginObject()
                .field("protocol", 1)
                .field("version", VERSION)
                .field("platform", platform.id)
                .field("software", software)
                .field("minecraft", minecraft)
                .field("updated_at", now)
                .field("interval", INTERVAL_SECONDS)
                .field("running", running)
                .endObject().toString();
    }

    // ------------------------------------------------------------------ setup helpers

    private void logSummary(Names names, Path dir) {
        Log.info("MC Toolkit Bridge " + VERSION + " on " + platform.id + " (" + software + ", Minecraft "
                + (minecraft == null ? "?" : minecraft) + "), runtime names: " + names.namespace.id
                + "; writing " + dir + " every " + INTERVAL_SECONDS + " s");
        Map<String, Boolean> features = mc.features();
        List<String> missing = new ArrayList<>();
        features.forEach((name, ok) -> {
            if (!ok) {
                missing.add(name);
            }
        });
        String line = "Capabilities: " + (features.size() - missing.size()) + "/" + features.size() + " available "
                + features.keySet();
        if (missing.isEmpty()) {
            Log.info(line);
        } else {
            Log.warn(line + "; UNAVAILABLE (written as null): " + missing);
        }
        List<String> unresolved = mc.unresolved();
        int total = mc.resolver.all.size();
        Log.info("Resolved " + (total - unresolved.size()) + "/" + total + " Minecraft members"
                + (unresolved.isEmpty() ? "" : "; absent on this version (other side of a version switch): " + unresolved));
    }

    private void loadExtension(Names names) {
        if (!Boolean.getBoolean("mctoolkit.bridge.selftest")) {
            return;
        }
        try {
            Class<?> type = Class.forName(Bridge.class.getPackage().getName() + ".SelfTest", true, Bridge.class.getClassLoader());
            extension = (Extension) type.getDeclaredConstructor(Names.class, Mc.class).newInstance(names, mc);
            Log.info("Development self-test enabled");
        } catch (ClassNotFoundException e) {
            Log.warn("-Dmctoolkit.bridge.selftest is set, but this is a release jar without the self-test");
        } catch (Throwable t) {
            Log.warn("The self-test could not start", t);
        }
    }

    private String levelFolder(Object server) {
        String folder = mc.levelFolder(server);
        if (folder != null) {
            return folder;
        }
        Properties props = new Properties();
        try (InputStream in = Files.newInputStream(Paths.get("server.properties"))) {
            props.load(in);
            String name = props.getProperty("level-name");
            if (name != null && !name.isBlank()) {
                return name.trim();
            }
        } catch (Exception ignored) {
            // fall back to the default name
        }
        return "world";
    }

    /** {@code -Dmctoolkit.bridge.dir}, then {@code MCTOOLKIT_BRIDGE_DIR}, then {@code .toolkit}; relative to the working directory. */
    static Path bridgeDir() {
        String configured = System.getProperty("mctoolkit.bridge.dir");
        if (configured == null || configured.isBlank()) {
            configured = System.getenv("MCTOOLKIT_BRIDGE_DIR");
        }
        if (configured == null || configured.isBlank()) {
            configured = ".toolkit";
        }
        Path path = Paths.get(configured.trim());
        return (path.isAbsolute() ? path : Paths.get("").toAbsolutePath().resolve(path)).normalize();
    }

    private static String readVersion() {
        try (InputStream in = Bridge.class.getResourceAsStream("bridge.properties")) {
            Properties props = new Properties();
            if (in != null) {
                props.load(in);
            }
            return props.getProperty("version", "1.0.0");
        } catch (Exception e) {
            return "1.0.0";
        }
    }
}
