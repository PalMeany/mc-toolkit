package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * The data of one 5-second write. On Spigot/Paper it is filled in one go on the main thread; on Folia the pieces
 * arrive from several region threads, and the cycle counts outstanding pieces ({@link #fork()}) and fires its
 * completion action once all of them are in (or the next cycle starts and flushes it as it is).
 */
final class Cycle {

    final long seq;
    volatile Double tps;
    volatile Double tpsOne;
    volatile Double tpsFive;
    volatile Double tpsFifteen;
    private volatile Double mspt;
    /** Overworld day time, 0..23999. */
    volatile Long time;
    final List<Worlds.Stats> worlds = Collections.synchronizedList(new ArrayList<Worlds.Stats>());

    /** Players online when the cycle started, in server order. */
    private final Set<UUID> expected = Collections.synchronizedSet(new LinkedHashSet<UUID>());
    private final Map<UUID, PlayerSnapshot> snapshots = new ConcurrentHashMap<>();
    private final Set<UUID> retired = Collections.newSetFromMap(new ConcurrentHashMap<UUID, Boolean>());

    private final AtomicInteger pending = new AtomicInteger(1); // 1 = not armed yet
    private final AtomicBoolean claimed = new AtomicBoolean();
    private volatile Runnable onComplete;

    Cycle(long seq) {
        this.seq = seq;
    }

    void expect(UUID player) {
        expected.add(player);
    }

    void put(PlayerSnapshot snapshot) {
        expected.add(snapshot.uuid);
        snapshots.put(snapshot.uuid, snapshot);
    }

    /** The player left before their data could be read: leave them out of this cycle. */
    void retire(UUID player) {
        retired.add(player);
    }

    /** Folia: keeps the worst (highest) MSPT of all regions that reported one. */
    synchronized void offerMspt(Double value) {
        if (value != null && !value.isNaN() && (mspt == null || value > mspt)) {
            mspt = value;
        }
    }

    Double mspt() {
        return mspt;
    }

    synchronized void mspt(Double value) {
        mspt = value;
    }

    /**
     * The players of this cycle in server order. A player whose data has not arrived (Folia, region busy) is taken
     * from {@code previous} so that a slow region does not make them flicker out of the files.
     */
    List<PlayerSnapshot> players(Map<UUID, PlayerSnapshot> previous) {
        List<UUID> order;
        synchronized (expected) {
            order = new ArrayList<>(expected);
        }
        List<PlayerSnapshot> players = new ArrayList<>(order.size());
        for (UUID id : order) {
            if (retired.contains(id)) {
                continue;
            }
            PlayerSnapshot snapshot = snapshots.get(id);
            if (snapshot == null && previous != null) {
                snapshot = previous.get(id);
            }
            if (snapshot != null) {
                players.add(snapshot);
            }
        }
        return players;
    }

    /** One more outstanding piece; run the returned action exactly once when it is done (or abandoned). */
    Runnable fork() {
        pending.incrementAndGet();
        AtomicBoolean done = new AtomicBoolean();
        return () -> {
            if (done.compareAndSet(false, true)) {
                release();
            }
        };
    }

    /** All pieces are forked: {@code action} runs as soon as every one of them is done (possibly right now). */
    void arm(Runnable action) {
        onComplete = action;
        release();
    }

    private void release() {
        if (pending.decrementAndGet() == 0) {
            Runnable action = onComplete;
            if (action != null) {
                action.run();
            }
        }
    }

    /** True exactly once: whoever gets it hands the cycle to the writer. */
    boolean claim() {
        return claimed.compareAndSet(false, true);
    }
}
