package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;

import io.github.palmeany.mctoolkit.bridge.selftest.MiniJson;
import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class DocumentsTest {

    static final Documents DOCUMENTS = new Documents("1.0.0", "paper", "Paper version 1.21.11-37 (MC: 1.21.11)", "1.21.11");

    static PlayerSnapshot snapshot(String name, String world, double x) {
        PlayerSnapshot snapshot = new PlayerSnapshot(UUID.nameUUIDFromBytes(name.getBytes()), name);
        snapshot.world = world;
        snapshot.dimension = world.endsWith("_nether") ? "nether" : "overworld";
        snapshot.x = x;
        snapshot.y = 64.0;
        snapshot.z = -1.005;
        return snapshot;
    }

    static Cycle cycle() {
        Cycle cycle = new Cycle(1);
        cycle.tps = 20.0000001;
        cycle.tpsOne = 19.973;
        cycle.tpsFive = 21.5; // Paper can report slightly above 20
        cycle.tpsFifteen = null;
        cycle.mspt(12.3149);
        cycle.time = 6000L;
        Worlds.Stats world = new Worlds.Stats("world", "overworld");
        world.chunks = 961;
        world.entityCount = 1300;
        Map<String, Integer> entities = new LinkedHashMap<>();
        entities.put("minecraft:item", 612);
        entities.put("minecraft:zombie", 188);
        world.entities = entities;
        Worlds.Stats nether = new Worlds.Stats("world_nether", "nether");
        cycle.worlds.add(world);
        cycle.worlds.add(nether);
        return cycle;
    }

    @Test
    void metaBlock() {
        assertEquals("{\"protocol\":1,\"version\":\"1.0.0\",\"platform\":\"paper\",\"software\":\"Paper version "
                + "1.21.11-37 (MC: 1.21.11)\",\"minecraft\":\"1.21.11\",\"updated_at\":1791956941,\"interval\":5,"
                + "\"running\":true}", Json.write(DOCUMENTS.meta(1791956941L, true)));
    }

    @Test
    void performanceDocument() {
        List<PlayerSnapshot> players = Arrays.asList(snapshot("a", "world", 1), snapshot("b", "world", 2),
                snapshot("c", "world_nether", 3));
        String json = DOCUMENTS.performance(DOCUMENTS.meta(5, true), cycle(), players);
        assertEquals("{\"bridge\":{\"protocol\":1,\"version\":\"1.0.0\",\"platform\":\"paper\",\"software\":\"Paper "
                + "version 1.21.11-37 (MC: 1.21.11)\",\"minecraft\":\"1.21.11\",\"updated_at\":5,\"interval\":5,"
                + "\"running\":true},\"tps\":20.0,\"mspt\":12.31,\"tps_avg\":{\"one\":19.97,\"five\":20.0,"
                + "\"fifteen\":null},\"worlds\":[{\"name\":\"world\",\"dimension\":\"overworld\",\"players\":2,"
                + "\"chunks\":961,\"entity_count\":1300,\"entities\":{\"minecraft:item\":612,\"minecraft:zombie\":188}},"
                + "{\"name\":\"world_nether\",\"dimension\":\"nether\",\"players\":1,\"chunks\":null,"
                + "\"entity_count\":null,\"entities\":null}]}", json);
        MiniJson.parse(json);
    }

    @Test
    void stateAndMapDocuments() {
        List<PlayerSnapshot> players = Collections.singletonList(snapshot("Steve", "world", 156.5));
        Map<String, Object> state = MiniJson.object(DOCUMENTS.state(DOCUMENTS.meta(5, true), players));
        Map<?, ?> byId = (Map<?, ?>) state.get("players");
        String id = players.get(0).id();
        assertEquals(Collections.singleton(id), byId.keySet());
        Map<?, ?> steve = (Map<?, ?>) byId.get(id);
        assertEquals(16, steve.size()); // every field present, unknown ones null
        assertNull(steve.get("ping"));
        assertEquals(-1.01, steve.get("z"));

        Map<String, Object> map = MiniJson.object(DOCUMENTS.map(DOCUMENTS.meta(5, true), 6000L, players));
        assertEquals(6000L, map.get("time"));
        assertEquals("[{uuid=" + id + ", name=Steve, world=world, dimension=overworld, x=156.5, y=64.0, z=-1.01}]",
                map.get("players").toString());

        String empty = DOCUMENTS.state(DOCUMENTS.meta(5, false), Collections.<PlayerSnapshot>emptyList());
        assertEquals("{}", Json.write(MiniJson.object(empty).get("players")));
        assertEquals("[]", DOCUMENTS.map(DOCUMENTS.meta(5, false), null, Collections.<PlayerSnapshot>emptyList())
                .replaceAll(".*\"players\":(\\[\\]).*", "$1"));
    }

    @Test
    void playerBodyGetsTheMetaBlockInFront() {
        PlayerSnapshot snapshot = snapshot("Alex", "world", 0);
        Map<String, Object> detail = new LinkedHashMap<>();
        detail.put("uuid", snapshot.id());
        detail.put("name", "Alex");
        detail.put("inventory", Collections.emptyList());
        snapshot.detail = detail;
        String body = DOCUMENTS.playerBody(snapshot);
        String full = Documents.withMeta(DOCUMENTS.meta(7, true), body);
        Map<String, Object> parsed = MiniJson.object(full);
        assertEquals(Arrays.asList("bridge", "uuid", "name", "inventory"), Arrays.asList(parsed.keySet().toArray()));
        assertEquals(7L, ((Map<?, ?>) parsed.get("bridge")).get("updated_at"));
        assertEquals("{\"bridge\":{}}", Documents.withMeta(new LinkedHashMap<String, Object>(), "{}"));
    }
}
