package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayDeque;
import java.util.Iterator;

/**
 * Measured TPS: the server's tick counter sampled against wall time on every bridge cycle.
 * "latest" covers the last interval (5 s); the averages cover the last 1, 5 and 15 minutes (or the
 * uptime, when shorter). Values are capped at 20.
 *
 * <p>An empty 1.21.2+ server that paused itself skips whole ticks on purpose; an interval that
 * starts or ends paused is counted at full speed (as Paper's loop-based TPS does) instead of
 * reading as a lag spike.
 */
final class Tps {

    static final long MINUTE = 60_000_000_000L;
    private static final double MAX = 20.0;

    /** {nanoTime, effective tick count} pairs, oldest first; one sample older than 15 minutes is kept. */
    private final ArrayDeque<long[]> samples = new ArrayDeque<>();
    private int lastRaw;
    private boolean lastPaused;

    synchronized void add(long nanos, int ticks, boolean paused) {
        long effective = ticks;
        long[] last = samples.peekLast();
        if (last != null) {
            long delta = Math.max(0, ticks - lastRaw); // int arithmetic survives counter wrap-around
            if (paused || lastPaused) {
                delta = Math.max(delta, Math.round((nanos - last[0]) / 1e9 * MAX));
            }
            effective = last[1] + delta;
        }
        lastRaw = ticks;
        lastPaused = paused;
        samples.addLast(new long[] {nanos, effective});
        while (samples.size() > 2) {
            Iterator<long[]> it = samples.iterator();
            it.next();
            if (nanos - it.next()[0] >= 15 * MINUTE) {
                samples.removeFirst();
            } else {
                break;
            }
        }
    }

    synchronized Double latest() {
        if (samples.size() < 2) {
            return null;
        }
        Iterator<long[]> it = samples.descendingIterator();
        long[] newest = it.next();
        return rate(it.next(), newest);
    }

    /** Average over the last {@code window} nanoseconds (the whole history when it is shorter). */
    synchronized Double average(long window) {
        if (samples.size() < 2) {
            return null;
        }
        long[] newest = samples.peekLast();
        long[] from = samples.peekFirst();
        for (long[] s : samples) {
            if (newest[0] - s[0] >= window) {
                from = s;
            } else {
                break;
            }
        }
        return rate(from, newest);
    }

    private static Double rate(long[] from, long[] to) {
        double seconds = (to[0] - from[0]) / 1e9;
        if (seconds <= 0) {
            return null;
        }
        double tps = Math.max(0, to[1] - from[1]) / seconds;
        return Math.min(MAX, tps);
    }
}
