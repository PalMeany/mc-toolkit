package io.github.palmeany.mctoolkit.bridge;

import static io.github.palmeany.mctoolkit.bridge.Fakes.answers;
import static io.github.palmeany.mctoolkit.bridge.Fakes.proxy;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import org.bukkit.Chunk;
import org.bukkit.World;
import org.bukkit.entity.Entity;
import org.bukkit.entity.EntityType;
import org.junit.jupiter.api.Test;

class WorldsTest {

    static Entity entity(EntityType type) {
        return proxy(Entity.class, answers("getType", type));
    }

    @Test
    void countsEntitiesByTypeOnTheOwningThread() {
        List<Entity> entities = new ArrayList<>();
        for (int i = 0; i < 5; i++) {
            entities.add(entity(EntityType.DROPPED_ITEM));
        }
        for (int i = 0; i < 3; i++) {
            entities.add(entity(EntityType.ZOMBIE));
        }
        entities.add(entity(EntityType.EXPERIENCE_ORB));
        World world = proxy(World.class, answers(
                "getName", "world",
                "getEnvironment", World.Environment.NORMAL,
                "getEntities", entities,
                "getLoadedChunks", new Chunk[961]));
        Worlds.Stats stats = Worlds.read(world);
        assertEquals("world", stats.name);
        assertEquals("overworld", stats.dimension);
        assertEquals(961, stats.chunks); // 1.8.8 has no World#getChunkCount: counted from getLoadedChunks
        assertEquals(9, stats.entityCount);
        assertEquals("{minecraft:item=5, minecraft:zombie=3, minecraft:experience_orb=1}", stats.entities.toString());
    }

    @Test
    void concurrentReadNeverListsEntities() {
        World world = proxy(World.class, answers("getName", "world", "getEnvironment", World.Environment.NORMAL));
        Worlds.Stats stats = Worlds.readConcurrent(world);
        assertNull(stats.entities);
        assertNull(stats.entityCount); // no Paper counter on the 1.8.8 API
        assertNull(stats.chunks); // getLoadedChunks is not touched off the owning thread
    }

    @Test
    void topKeepsTheTenLargestBiggestFirst() {
        Map<String, Integer> counts = new HashMap<>();
        for (int i = 0; i < 15; i++) {
            counts.put("minecraft:type" + (char) ('a' + i), i);
        }
        counts.put("minecraft:tie", 14);
        Map<String, Integer> top = Worlds.top(counts, 10);
        assertEquals(10, top.size());
        assertEquals("{minecraft:tie=14, minecraft:typeo=14, minecraft:typen=13, minecraft:typem=12, "
                + "minecraft:typel=11, minecraft:typek=10, minecraft:typej=9, minecraft:typei=8, minecraft:typeh=7, "
                + "minecraft:typeg=6}", top.toString());
    }

    @Test
    void entityIdsOnTheLegacyApi() {
        assertEquals("minecraft:item", Ids.entity(EntityType.DROPPED_ITEM));
        assertEquals("minecraft:zombie", Ids.entity(EntityType.ZOMBIE)); // 1.8 save id "Zombie" is not lowercase
        assertEquals("minecraft:pig_zombie", Ids.entity(EntityType.PIG_ZOMBIE));
        assertEquals("minecraft:unknown", Ids.entity(null));
    }
}
