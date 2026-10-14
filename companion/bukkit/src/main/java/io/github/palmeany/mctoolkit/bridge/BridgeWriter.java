package io.github.palmeany.mctoolkit.bridge;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.DirectoryStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.logging.Level;
import java.util.logging.Logger;

/**
 * Turns cycles into files. Single writer (all methods synchronized): cycles that arrive out of order are dropped,
 * every file is written to {@code <name>.tmp} and renamed over the target, per-player files are only rewritten when
 * their content changed, and files of players who are no longer online are deleted.
 */
final class BridgeWriter {

    static final String STATE = "state.json";
    static final String MAP = "map.json";
    static final String PERFORMANCE = "performance.json";
    static final String PLAYERS = "players";
    private static final String TMP = ".tmp";
    private static final long WARN_EVERY_MILLIS = 60_000L;

    private final Path directory;
    private final Path playersDirectory;
    private final Documents documents;
    private final Logger logger;
    private final Clock clock;

    /** Body last written per player file, to skip rewrites of unchanged content. */
    private final Map<UUID, String> writtenBodies = new HashMap<>();
    private Map<UUID, PlayerSnapshot> lastPlayers = Collections.emptyMap();
    private Cycle last;
    private boolean closed;
    private final Map<String, Long> lastWarning = new HashMap<>();

    interface Clock {
        long epochSeconds();
    }

    BridgeWriter(Path directory, Documents documents, Logger logger) {
        this(directory, documents, logger, () -> System.currentTimeMillis() / 1000L);
    }

    BridgeWriter(Path directory, Documents documents, Logger logger, Clock clock) {
        this.directory = directory;
        this.playersDirectory = directory.resolve(PLAYERS);
        this.documents = documents;
        this.logger = logger;
        this.clock = clock;
    }

    Path directory() {
        return directory;
    }

    /** Writes every file for {@code cycle}; false when the cycle is older than one already written or after close. */
    synchronized boolean write(Cycle cycle) {
        if (closed || (last != null && cycle.seq <= last.seq)) {
            return false;
        }
        last = cycle;
        List<PlayerSnapshot> players = cycle.players(lastPlayers);
        Map<String, Object> meta = documents.meta(clock.epochSeconds(), true);
        if (!ensureDirectories()) {
            return false;
        }

        // player files first: whoever sees a player in state.json can open their detail file
        Map<UUID, PlayerSnapshot> online = new HashMap<>();
        for (PlayerSnapshot player : players) {
            online.put(player.uuid, player);
            String body = documents.playerBody(player);
            Path file = playersDirectory.resolve(player.id() + ".json");
            if (!body.equals(writtenBodies.get(player.uuid)) || !Files.exists(file)) {
                if (put(file, Documents.withMeta(meta, body))) {
                    writtenBodies.put(player.uuid, body);
                }
            }
        }
        writtenBodies.keySet().retainAll(online.keySet());
        lastPlayers = online;

        put(directory.resolve(STATE), documents.state(meta, players));
        put(directory.resolve(MAP), documents.map(meta, cycle.time, players));
        put(directory.resolve(PERFORMANCE), documents.performance(meta, cycle, players));
        sweepPlayers(online.keySet());
        return true;
    }

    /**
     * The final write of a clean shutdown: empty player lists, {@code "running": false}, no player files left.
     * Nothing is written after this.
     */
    synchronized void close() {
        if (closed) {
            return;
        }
        closed = true;
        Cycle cycle = last != null ? last : new Cycle(0);
        List<PlayerSnapshot> nobody = Collections.emptyList();
        Map<String, Object> meta = documents.meta(clock.epochSeconds(), false);
        if (!ensureDirectories()) {
            return;
        }
        sweepPlayers(Collections.<UUID>emptySet());
        writtenBodies.clear();
        lastPlayers = Collections.emptyMap();
        put(directory.resolve(STATE), documents.state(meta, nobody));
        put(directory.resolve(MAP), documents.map(meta, cycle.time, nobody));
        put(directory.resolve(PERFORMANCE), documents.performance(meta, cycle, nobody));
    }

    private boolean ensureDirectories() {
        try {
            Files.createDirectories(playersDirectory);
            return true;
        } catch (IOException | RuntimeException e) {
            warn("mkdir", "Cannot create " + playersDirectory, e);
            return false;
        }
    }

    /** Atomic replace: write {@code <name>.tmp} next to the target, then rename it over the target. */
    boolean put(Path target, String content) {
        Path temporary = target.resolveSibling(target.getFileName() + TMP);
        try {
            Files.write(temporary, content.getBytes(StandardCharsets.UTF_8));
            try {
                Files.move(temporary, target, StandardCopyOption.ATOMIC_MOVE);
            } catch (IOException | UnsupportedOperationException atomicFailed) {
                Files.move(temporary, target, StandardCopyOption.REPLACE_EXISTING);
            }
            return true;
        } catch (IOException | RuntimeException e) {
            warn("write:" + target.getFileName(), "Cannot write " + target, e);
            try {
                Files.deleteIfExists(temporary);
            } catch (IOException | RuntimeException ignored) {
                // nothing more to do
            }
            return false;
        }
    }

    /** Deletes player files (and leftover temporaries) of everyone not in {@code online}. */
    private void sweepPlayers(Set<UUID> online) {
        Set<Path> doomed = new HashSet<>();
        try (DirectoryStream<Path> files = Files.newDirectoryStream(playersDirectory)) {
            for (Path file : files) {
                String name = file.getFileName().toString();
                if (name.endsWith(TMP)) {
                    doomed.add(file);
                } else if (name.endsWith(".json")) {
                    UUID id = parse(name.substring(0, name.length() - ".json".length()));
                    if (id != null && !online.contains(id)) {
                        doomed.add(file);
                    }
                }
            }
        } catch (IOException | RuntimeException e) {
            warn("sweep", "Cannot list " + playersDirectory, e);
            return;
        }
        for (Path file : doomed) {
            try {
                Files.deleteIfExists(file);
            } catch (IOException | RuntimeException e) {
                warn("delete", "Cannot delete " + file, e);
            }
        }
    }

    private static UUID parse(String text) {
        try {
            return UUID.fromString(text);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }

    /** At most one warning per minute per kind, so a read-only disk does not flood the console every 5 seconds. */
    private void warn(String kind, String message, Throwable error) {
        long now = System.currentTimeMillis();
        Long before = lastWarning.get(kind);
        if (before != null && now - before < WARN_EVERY_MILLIS) {
            return;
        }
        lastWarning.put(kind, now);
        if (logger != null) {
            logger.log(Level.WARNING, message + ": " + error);
        }
    }
}
