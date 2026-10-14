package io.github.palmeany.mctoolkit.bridge;

/** Owns the threads: when data is collected, where, and when the writer runs. */
interface Driver {

    void start();

    void stop();

    String tpsSource();

    String msptSource();
}
