package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;

import org.junit.jupiter.api.Test;

class TpsTest {

    private static final long SECOND = 1_000_000_000L;

    @Test
    void needsTwoSamples() {
        Tps tps = new Tps();
        assertNull(tps.latest());
        tps.add(0, 100, false);
        assertNull(tps.latest());
        assertNull(tps.average(Tps.MINUTE));
    }

    @Test
    void steadyServerReadsTwenty() {
        Tps tps = new Tps();
        for (int i = 0; i <= 200; i++) {
            tps.add(i * 5 * SECOND, i * 100, false);
        }
        assertEquals(20.0, tps.latest(), 1e-9);
        assertEquals(20.0, tps.average(Tps.MINUTE), 1e-9);
        assertEquals(20.0, tps.average(15 * Tps.MINUTE), 1e-9);
    }

    @Test
    void averagesCoverTheirOwnWindow() {
        Tps tps = new Tps();
        int ticks = 0;
        int i = 0;
        for (; i <= 168; i++) {          // 14 minutes at 20 TPS
            tps.add(i * 5 * SECOND, ticks, false);
            ticks += 100;
        }
        ticks -= 50;                      // from here on 10 TPS
        for (; i <= 180; i++) {          // the last minute
            tps.add(i * 5 * SECOND, ticks, false);
            ticks += 50;
        }
        assertEquals(10.0, tps.latest(), 1e-9);
        assertEquals(10.0, tps.average(Tps.MINUTE), 1e-9);
        assertEquals(18.0, tps.average(5 * Tps.MINUTE), 1e-9);
        assertEquals(20.0 - 10.0 * 12 / 180, tps.average(15 * Tps.MINUTE), 1e-9);
    }

    @Test
    void shortUptimeUsesWholeHistory() {
        Tps tps = new Tps();
        tps.add(0, 0, false);
        tps.add(5 * SECOND, 75, false);
        tps.add(10 * SECOND, 150, false);
        assertEquals(15.0, tps.average(Tps.MINUTE), 1e-9);
        assertEquals(15.0, tps.average(15 * Tps.MINUTE), 1e-9);
    }

    @Test
    void catchUpBurstsAreCappedAtTwenty() {
        Tps tps = new Tps();
        tps.add(0, 0, false);
        tps.add(5 * SECOND, 150, false);
        assertEquals(20.0, tps.latest(), 1e-9);
    }

    @Test
    void pausedEmptyServerIsNotALagSpike() {
        Tps tps = new Tps();
        tps.add(0, 0, false);
        tps.add(5 * SECOND, 100, false);
        tps.add(10 * SECOND, 140, true);   // paused after 2 s of this interval
        assertEquals(20.0, tps.latest(), 1e-9);
        tps.add(15 * SECOND, 140, true);   // fully paused: counter stands still
        assertEquals(20.0, tps.latest(), 1e-9);
        tps.add(20 * SECOND, 180, false);  // resumed 3 s before the sample
        assertEquals(20.0, tps.latest(), 1e-9);
        tps.add(25 * SECOND, 230, false);  // running, now genuinely at 10 TPS
        assertEquals(10.0, tps.latest(), 1e-9);
        assertEquals(450.0 / 25, tps.average(Tps.MINUTE), 1e-9);
    }

    @Test
    void stalledServerReadsZero() {
        Tps tps = new Tps();
        tps.add(0, 500, false);
        tps.add(5 * SECOND, 500, false);
        assertEquals(0.0, tps.latest(), 1e-9);
    }

    @Test
    void survivesTickCounterWrapAround() {
        Tps tps = new Tps();
        tps.add(0, Integer.MAX_VALUE - 49, false);
        tps.add(5 * SECOND, Integer.MIN_VALUE + 50, false);
        assertEquals(20.0, tps.latest(), 1e-9);
    }
}
