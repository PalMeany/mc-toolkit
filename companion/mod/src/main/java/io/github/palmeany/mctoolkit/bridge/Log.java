package io.github.palmeany.mctoolkit.bridge;

import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;

/** Logging through SLF4J (present on every supported server), falling back to stdout. */
final class Log {

    private interface Sink {
        void info(String message);

        void warn(String message, Throwable error);
    }

    private static final Set<String> ONCE = ConcurrentHashMap.newKeySet();
    private static final Sink SINK = createSink();

    private Log() {
    }

    static void info(String message) {
        SINK.info(message);
    }

    static void warn(String message) {
        SINK.warn(message, null);
    }

    static void warn(String message, Throwable error) {
        SINK.warn(message, error);
    }

    /** Logs a warning only the first time {@code key} is seen in this run. */
    static void warnOnce(String key, String message, Throwable error) {
        if (ONCE.add(key)) {
            SINK.warn(message, error);
        }
    }

    private static Sink createSink() {
        try {
            Class.forName("org.slf4j.LoggerFactory", false, Log.class.getClassLoader());
            return Slf4j.create();
        } catch (Throwable missing) {
            return new Sink() {
                @Override
                public void info(String message) {
                    System.out.println("[mctoolkit_bridge] " + message);
                }

                @Override
                public void warn(String message, Throwable error) {
                    System.out.println("[mctoolkit_bridge] WARN " + message + (error == null ? "" : " (" + error + ")"));
                }
            };
        }
    }

    /** Separate class so a server without SLF4J never links against it. */
    private static final class Slf4j {
        static Sink create() {
            org.slf4j.Logger logger = org.slf4j.LoggerFactory.getLogger("mctoolkit_bridge");
            return new Sink() {
                @Override
                public void info(String message) {
                    logger.info(message);
                }

                @Override
                public void warn(String message, Throwable error) {
                    if (error == null) {
                        logger.warn(message);
                    } else {
                        logger.warn(message, error);
                    }
                }
            };
        }
    }
}
