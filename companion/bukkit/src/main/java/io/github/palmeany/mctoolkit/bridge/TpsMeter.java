package io.github.palmeany.mctoolkit.bridge;

/**
 * TPS for servers without Paper's {@code Server#getTPS()} (Spigot, CraftBukkit, some hybrids): a task that runs
 * every tick calls {@link #tick(long)}; every 20 ticks the elapsed time becomes one sample, and averages are ticks
 * over elapsed time across the most recent samples (5 ≈ 5 s, 60 ≈ 1 min, 300 ≈ 5 min, 900 ≈ 15 min at full speed).
 */
final class TpsMeter {

    static final int TICKS_PER_SAMPLE = 20;
    static final int LATEST = 5;
    static final int ONE_MINUTE = 60;
    static final int FIVE_MINUTES = 300;
    static final int FIFTEEN_MINUTES = 900;

    private final long[] samples = new long[FIFTEEN_MINUTES];
    private int size;
    private int next;
    private long sampleStart = Long.MIN_VALUE;
    private int ticks;

    /** Called once per server tick with {@link System#nanoTime()}. */
    synchronized void tick(long nanoTime) {
        if (sampleStart == Long.MIN_VALUE) {
            sampleStart = nanoTime;
            return;
        }
        if (++ticks < TICKS_PER_SAMPLE) {
            return;
        }
        samples[next] = nanoTime - sampleStart;
        next = (next + 1) % samples.length;
        if (size < samples.length) {
            size++;
        }
        sampleStart = nanoTime;
        ticks = 0;
    }

    /** Ticks per second over the last {@code count} samples (fewer while warming up), capped at 20; null before any. */
    synchronized Double average(int count) {
        int n = Math.min(count, size);
        if (n == 0) {
            return null;
        }
        long elapsed = 0;
        for (int i = 1; i <= n; i++) {
            elapsed += samples[(next - i + samples.length) % samples.length];
        }
        if (elapsed <= 0) {
            return Documents.MAX_TPS;
        }
        return Math.min(Documents.MAX_TPS, n * (double) TICKS_PER_SAMPLE * 1_000_000_000d / elapsed);
    }
}
