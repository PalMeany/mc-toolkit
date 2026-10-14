package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class OutputTest {

    private static final String META = "{\"protocol\":1}";
    private static final String A = "0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10";
    private static final String B = "11111111-2222-4333-8444-555555555555";

    @TempDir
    Path dir;

    private static Snapshot.Player player(String uuid, int count) {
        Snapshot.Player p = new Snapshot.Player();
        p.uuid = uuid;
        p.name = "Steve";
        p.mode = "survival";
        p.world = "world_nether";
        p.dimension = "nether";
        p.x = 156.5;
        p.y = 70.0;
        p.z = -307.254;
        p.health = 18.5;
        p.maxHealth = 20.0;
        p.food = 17;
        p.saturation = 3.2;
        p.level = 30;
        p.exp = 0.4242;
        p.ping = null;
        p.op = false;
        Snapshot.Item sword = new Snapshot.Item();
        sword.slot = 0;
        sword.id = "minecraft:diamond_sword";
        sword.count = count;
        sword.name = "Excalibur";
        sword.enchanted = true;
        sword.damage = 12;
        sword.maxDamage = 1561;
        p.inventory = new ArrayList<>(List.of(sword));
        p.equipmentKnown = true;
        p.enderChest = new ArrayList<>();
        p.effects = new ArrayList<>();
        return p;
    }

    @Test
    void stateFileFollowsTheProtocol() throws IOException {
        Output out = new Output(dir);
        out.writeState(META, List.of(player(A, 1)), Map.of(A, 1791950000L));
        assertEquals("{\"bridge\":{\"protocol\":1},\"players\":{\"" + A + "\":{\"name\":\"Steve\",\"mode\":\"survival\","
                        + "\"world\":\"world_nether\",\"dimension\":\"nether\",\"x\":156.5,\"y\":70.0,\"z\":-307.25,"
                        + "\"health\":18.5,\"max_health\":20.0,\"food\":17,\"saturation\":3.2,\"level\":30,\"exp\":0.42,"
                        + "\"ping\":null,\"op\":false,\"joined_at\":1791950000}}}",
                Files.readString(dir.resolve("state.json")));
        assertNoTempFiles();
    }

    @Test
    void performanceAndMapFiles() throws IOException {
        Output out = new Output(dir);
        Snapshot.World world = new Snapshot.World();
        world.name = "world";
        world.dimension = "overworld";
        world.players = 1;
        world.chunks = 961;
        world.entityCount = 800;
        world.topEntities = new LinkedHashMap<>(Map.of("minecraft:item", 612));
        Snapshot.World unknown = new Snapshot.World();
        unknown.name = "world_nether";
        unknown.dimension = "nether";
        out.writePerformance(META, 19.976, 19.97, 19.99, 20.0, 12.314, List.of(world, unknown));
        assertEquals("{\"bridge\":{\"protocol\":1},\"tps\":19.98,\"mspt\":12.31,\"tps_avg\":{\"one\":19.97,\"five\":19.99,"
                        + "\"fifteen\":20.0},\"worlds\":[{\"name\":\"world\",\"dimension\":\"overworld\",\"players\":1,"
                        + "\"chunks\":961,\"entity_count\":800,\"entities\":{\"minecraft:item\":612}},{\"name\":\"world_nether\","
                        + "\"dimension\":\"nether\",\"players\":0,\"chunks\":null,\"entity_count\":null,\"entities\":null}]}",
                Files.readString(dir.resolve("performance.json")));
        out.writeMap(META, 6000L, List.of(player(A, 1)));
        assertEquals("{\"bridge\":{\"protocol\":1},\"time\":6000,\"players\":[{\"uuid\":\"" + A + "\",\"name\":\"Steve\","
                        + "\"world\":\"world_nether\",\"dimension\":\"nether\",\"x\":156.5,\"y\":70.0,\"z\":-307.25}]}",
                Files.readString(dir.resolve("map.json")));
    }

    @Test
    void detailFilesAreWrittenOnChangeAndDeletedWhenOffline() throws IOException {
        Output out = new Output(dir);
        Path a = dir.resolve("players").resolve(A + ".json");
        Path b = dir.resolve("players").resolve(B + ".json");
        Files.createDirectories(a.getParent());
        Files.writeString(dir.resolve("players").resolve("ffffffff-ffff-4fff-8fff-ffffffffffff.json"), "stale");
        Files.writeString(dir.resolve("players").resolve("leftover.json.tmp"), "x");

        out.writePlayers("{\"t\":1}", List.of(player(A, 1), player(B, 1)));
        String first = Files.readString(a);
        assertTrue(first.startsWith("{\"bridge\":{\"t\":1},\"uuid\":\"" + A + "\",\"name\":\"Steve\",\"inventory\":[{\"slot\":0,"));
        assertTrue(first.endsWith("\"armor\":{\"head\":null,\"chest\":null,\"legs\":null,\"feet\":null},\"offhand\":null,"
                + "\"ender_chest\":[],\"effects\":[]}"));
        assertEquals(List.of(A + ".json", B + ".json"), listPlayers(), "stale files and temp files are removed");

        // unchanged content: not rewritten, even though the meta block (updated_at) differs
        Files.writeString(a, "marker");
        out.writePlayers("{\"t\":2}", List.of(player(A, 1), player(B, 1)));
        assertEquals("marker", Files.readString(a));

        // changed content: rewritten with the new meta block
        out.writePlayers("{\"t\":3}", List.of(player(A, 2), player(B, 1)));
        assertTrue(Files.readString(a).startsWith("{\"bridge\":{\"t\":3}"));

        // a deleted file comes back even when unchanged
        Files.delete(b);
        out.writePlayers("{\"t\":4}", List.of(player(A, 2), player(B, 1)));
        assertTrue(Files.exists(b));

        // B goes offline
        out.writePlayers("{\"t\":5}", List.of(player(A, 2)));
        assertFalse(Files.exists(b));
        assertEquals(List.of(A + ".json"), listPlayers());

        out.clearPlayers();
        assertEquals(List.of(), listPlayers());
        assertNoTempFiles();
    }

    @Test
    void unknownDetailIsNull() throws IOException {
        Output out = new Output(dir);
        Snapshot.Player p = player(A, 1);
        p.inventory = null;
        p.equipmentKnown = false;
        p.enderChest = null;
        p.effects = null;
        out.writePlayers(META, List.of(p));
        assertTrue(Files.readString(dir.resolve("players").resolve(A + ".json"))
                .endsWith("\"inventory\":null,\"armor\":null,\"offhand\":null,\"ender_chest\":null,\"effects\":null}"));
    }

    private List<String> listPlayers() throws IOException {
        try (Stream<Path> files = Files.list(dir.resolve("players"))) {
            return files.map(f -> f.getFileName().toString()).sorted().toList();
        }
    }

    private void assertNoTempFiles() throws IOException {
        try (Stream<Path> files = Files.walk(dir)) {
            assertFalse(files.anyMatch(f -> f.toString().endsWith(".tmp")));
        }
    }
}
