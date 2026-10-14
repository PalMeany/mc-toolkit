package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import io.github.palmeany.mctoolkit.bridge.selftest.MiniJson;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicLong;
import java.util.stream.Collectors;
import java.util.stream.Stream;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class BridgeWriterTest {

    @TempDir
    Path work;

    final AtomicLong now = new AtomicLong(1791956940L);
    long seq;

    BridgeWriter writer() {
        return new BridgeWriter(work.resolve(".toolkit"), DocumentsTest.DOCUMENTS, null, now::get);
    }

    PlayerSnapshot player(String name, int cobblestone) {
        PlayerSnapshot snapshot = DocumentsTest.snapshot(name, "world", 10);
        Map<String, Object> detail = new LinkedHashMap<>();
        detail.put("uuid", snapshot.id());
        detail.put("name", name);
        Map<String, Object> item = new LinkedHashMap<>();
        item.put("slot", 0);
        item.put("id", "minecraft:cobblestone");
        item.put("count", cobblestone);
        detail.put("inventory", Collections.singletonList(item));
        snapshot.detail = detail;
        return snapshot;
    }

    Cycle cycle(PlayerSnapshot... players) {
        Cycle cycle = DocumentsTest.cycle();
        Cycle fresh = new Cycle(++seq);
        fresh.tps = cycle.tps;
        fresh.time = cycle.time;
        fresh.worlds.addAll(cycle.worlds);
        for (PlayerSnapshot player : players) {
            fresh.put(player);
        }
        return fresh;
    }

    Map<String, Object> read(String file) throws IOException {
        return MiniJson.object(new String(Files.readAllBytes(work.resolve(".toolkit").resolve(file)), StandardCharsets.UTF_8));
    }

    static long updatedAt(Map<String, Object> document) {
        return (Long) ((Map<?, ?>) document.get("bridge")).get("updated_at");
    }

    List<String> files() throws IOException {
        try (Stream<Path> walk = Files.walk(work)) {
            return walk.filter(Files::isRegularFile).map(p -> work.relativize(p).toString().replace('\\', '/'))
                    .sorted().collect(Collectors.toList());
        }
    }

    @Test
    void writesEveryFileAtomically() throws IOException {
        BridgeWriter writer = writer();
        PlayerSnapshot steve = player("Steve", 64);
        assertTrue(writer.write(cycle(steve)));
        List<String> expected = new ArrayList<>();
        expected.add(".toolkit/map.json");
        expected.add(".toolkit/performance.json");
        expected.add(".toolkit/players/" + steve.id() + ".json");
        expected.add(".toolkit/state.json");
        assertEquals(expected, files()); // no .tmp left behind
        for (String file : new String[] {"state.json", "map.json", "performance.json", "players/" + steve.id() + ".json"}) {
            Map<String, Object> document = read(file);
            Map<?, ?> meta = (Map<?, ?>) document.get("bridge");
            assertEquals(true, meta.get("running"), file);
            assertEquals(1791956940L, meta.get("updated_at"), file);
        }
        assertEquals(Collections.singleton(steve.id()), ((Map<?, ?>) read("state.json").get("players")).keySet());
        assertEquals(64L, ((Map<?, ?>) ((List<?>) read("players/" + steve.id() + ".json").get("inventory")).get(0)).get("count"));
    }

    @Test
    void playerFilesAreOnlyRewrittenWhenTheirContentChanges() throws IOException {
        BridgeWriter writer = writer();
        writer.write(cycle(player("Steve", 64)));
        String file = "players/" + player("Steve", 0).id() + ".json";

        now.addAndGet(5);
        writer.write(cycle(player("Steve", 64)));
        assertEquals(1791956945L, updatedAt(read("state.json")));
        assertEquals(1791956940L, updatedAt(read(file))); // unchanged: not rewritten

        now.addAndGet(5);
        writer.write(cycle(player("Steve", 32)));
        assertEquals(1791956950L, updatedAt(read(file)));
        assertEquals(32L, ((Map<?, ?>) ((List<?>) read(file).get("inventory")).get(0)).get("count"));

        Files.delete(work.resolve(".toolkit").resolve(file));
        now.addAndGet(5);
        writer.write(cycle(player("Steve", 32)));
        assertTrue(Files.exists(work.resolve(".toolkit").resolve(file)), "a deleted file is written again");
    }

    @Test
    void offlinePlayersFilesAreDeletedAndForeignFilesKept() throws IOException {
        Path players = work.resolve(".toolkit/players");
        Files.createDirectories(players);
        String stale = UUID.randomUUID() + ".json";
        Files.write(players.resolve(stale), "{}".getBytes(StandardCharsets.UTF_8));
        Files.write(players.resolve("notes.txt"), "keep".getBytes(StandardCharsets.UTF_8));
        Files.write(players.resolve("leftover.json.tmp"), "x".getBytes(StandardCharsets.UTF_8));

        BridgeWriter writer = writer();
        PlayerSnapshot steve = player("Steve", 1);
        PlayerSnapshot alex = player("Alex", 1);
        writer.write(cycle(steve, alex));
        assertFalse(Files.exists(players.resolve(stale)), "file of a player from an earlier run");
        assertFalse(Files.exists(players.resolve("leftover.json.tmp")));
        assertTrue(Files.exists(players.resolve("notes.txt")));

        writer.write(cycle(alex));
        assertFalse(Files.exists(players.resolve(steve.id() + ".json")));
        assertTrue(Files.exists(players.resolve(alex.id() + ".json")));
        assertEquals(Collections.singleton(alex.id()), ((Map<?, ?>) read("state.json").get("players")).keySet());
    }

    @Test
    void olderCyclesAreDropped() throws IOException {
        BridgeWriter writer = writer();
        Cycle first = cycle(player("Steve", 1));
        Cycle second = cycle();
        assertTrue(writer.write(second));
        assertFalse(writer.write(first));
        assertEquals(0, ((Map<?, ?>) read("state.json").get("players")).size());
    }

    @Test
    void closeWritesTheFinalStateAndStopsWriting() throws IOException {
        BridgeWriter writer = writer();
        PlayerSnapshot steve = player("Steve", 1);
        writer.write(cycle(steve));
        now.addAndGet(3);
        writer.close();
        for (String file : new String[] {"state.json", "map.json", "performance.json"}) {
            Map<?, ?> meta = (Map<?, ?>) read(file).get("bridge");
            assertEquals(false, meta.get("running"), file);
            assertEquals(1791956943L, meta.get("updated_at"), file);
        }
        assertEquals(0, ((Map<?, ?>) read("state.json").get("players")).size());
        assertEquals(0, ((List<?>) read("map.json").get("players")).size());
        assertEquals(6000L, read("map.json").get("time"));
        assertEquals(0L, ((Map<?, ?>) ((List<?>) read("performance.json").get("worlds")).get(0)).get("players"));
        assertFalse(Files.exists(work.resolve(".toolkit/players").resolve(steve.id() + ".json")));
        assertFalse(writer.write(cycle(steve)));
        assertEquals(false, ((Map<?, ?>) read("state.json").get("bridge")).get("running"));
    }

    @Test
    void closeBeforeAnyCycleStillWritesFiles() throws IOException {
        BridgeWriter writer = writer();
        writer.close();
        assertEquals(false, ((Map<?, ?>) read("performance.json").get("bridge")).get("running"));
        assertEquals(null, read("performance.json").get("tps"));
    }

    @Test
    void missingSnapshotsFallBackToThePreviousCycle() throws IOException {
        BridgeWriter writer = writer();
        PlayerSnapshot steve = player("Steve", 1);
        PlayerSnapshot alex = player("Alex", 1);
        writer.write(cycle(steve, alex));
        Cycle partial = cycle();
        partial.expect(steve.uuid); // Folia: Steve's region did not answer in time
        partial.expect(alex.uuid);
        partial.retire(alex.uuid); // Alex logged out meanwhile
        writer.write(partial);
        assertEquals(Collections.singleton(steve.id()), ((Map<?, ?>) read("state.json").get("players")).keySet());
        assertTrue(Files.exists(work.resolve(".toolkit/players").resolve(steve.id() + ".json")));
        assertFalse(Files.exists(work.resolve(".toolkit/players").resolve(alex.id() + ".json")));
    }

    @Test
    void directoryOverride() {
        Path work = this.work.toAbsolutePath();
        assertEquals(work.resolve(".toolkit"), BridgePlugin.resolveDirectory(null, null, work));
        assertEquals(work.resolve(".toolkit"), BridgePlugin.resolveDirectory(" ", "", work));
        assertEquals(work.resolve("data/bridge"), BridgePlugin.resolveDirectory(null, "data/bridge", work));
        assertEquals(work.resolve("prop"), BridgePlugin.resolveDirectory("prop", "env", work));
        Path absolute = work.resolve("elsewhere").toAbsolutePath();
        assertEquals(absolute, BridgePlugin.resolveDirectory(absolute.toString(), null, work));
        assertEquals(work.resolve("x"), BridgePlugin.resolveDirectory("a/../x", null, work));
    }
}
