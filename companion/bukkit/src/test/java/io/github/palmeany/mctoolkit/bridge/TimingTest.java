package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.Collections;
import java.util.concurrent.atomic.AtomicInteger;
import org.junit.jupiter.api.Test;

/** TPS meter, cycle completion and version parsing. */
class TimingTest {

    static final long MILLI = 1_000_000L;

    static long feed(TpsMeter meter, long start, int ticks, long tickNanos) {
        long time = start;
        for (int i = 0; i < ticks; i++) {
            time += tickNanos;
            meter.tick(time);
        }
        return time;
    }

    @Test
    void tpsMeterAveragesTicksOverElapsedTime() {
        TpsMeter meter = new TpsMeter();
        assertNull(meter.average(TpsMeter.LATEST));
        meter.tick(0);
        long time = feed(meter, 0, 20 * 60, 50 * MILLI); // one minute at full speed
        assertEquals(20.0, meter.average(TpsMeter.ONE_MINUTE), 1e-9);
        time = feed(meter, time, 20 * 5, 100 * MILLI); // five seconds at half speed
        assertEquals(10.0, meter.average(TpsMeter.LATEST), 1e-9);
        // last 60 samples: 55 at 20 TPS (1 s each), 5 at 10 TPS (2 s each) → 1200 ticks / 65 s
        assertEquals(1200.0 / 65.0, meter.average(TpsMeter.ONE_MINUTE), 1e-9);
        // fewer samples than the window: average over what there is
        assertEquals(1300.0 / 70.0, meter.average(TpsMeter.FIFTEEN_MINUTES), 1e-9);
        feed(meter, time, 20, 10 * MILLI); // faster than 20 TPS (catch-up ticks) is capped
        assertEquals(20.0, meter.average(1), 1e-9);
    }

    @Test
    void tpsMeterKeepsFifteenMinutes() {
        TpsMeter meter = new TpsMeter();
        meter.tick(0);
        long time = feed(meter, 0, 20 * 1000, 100 * MILLI); // 1000 samples at 10 TPS
        feed(meter, time, 20 * 300, 50 * MILLI); // then 300 at 20 TPS
        assertEquals(20.0, meter.average(TpsMeter.FIVE_MINUTES), 1e-9);
        // window of 900: 300 at 20 TPS + 600 at 10 TPS → 18000 ticks / 1500 s
        assertEquals(12.0, meter.average(TpsMeter.FIFTEEN_MINUTES), 1e-9);
    }

    @Test
    void cycleCompletesOnceAllForksAreDone() {
        AtomicInteger completed = new AtomicInteger();
        Cycle cycle = new Cycle(1);
        Runnable a = cycle.fork();
        Runnable b = cycle.fork();
        cycle.arm(completed::incrementAndGet);
        a.run();
        a.run(); // a second report of the same piece does not count
        assertEquals(0, completed.get());
        b.run();
        assertEquals(1, completed.get());
        assertTrue(cycle.claim());
        assertFalse(cycle.claim());

        Cycle empty = new Cycle(2);
        empty.arm(completed::incrementAndGet);
        assertEquals(2, completed.get(), "nothing forked: completes when armed");
    }

    @Test
    void worstMsptWins() {
        Cycle cycle = new Cycle(1);
        cycle.offerMspt(null);
        cycle.offerMspt(12.0);
        cycle.offerMspt(Double.NaN);
        cycle.offerMspt(48.5);
        cycle.offerMspt(3.0);
        assertEquals(48.5, cycle.mspt());
        assertEquals(Collections.emptyList(), cycle.players(null));
    }

    @Test
    void minecraftVersionParsing() {
        assertEquals("1.8.8", Platform.parseBukkitVersion("1.8.8-R0.1-SNAPSHOT"));
        assertEquals("1.21.11", Platform.parseBukkitVersion("1.21.11-R0.1-SNAPSHOT"));
        assertEquals("26.3", Platform.parseBukkitVersion("26.3-R0.1-SNAPSHOT"));
        assertNull(Platform.parseBukkitVersion("unknown"));
        assertNull(Platform.parseBukkitVersion(null));
        assertEquals("1.8.8", Platform.parseServerVersion("git-Spigot-db6de12-18fbb24 (MC: 1.8.8)"));
        assertEquals("1.12.2", Platform.parseServerVersion("git-Paper-1620 (MC: 1.12.2)"));
        assertNull(Platform.parseServerVersion("custom build"));
    }
}
