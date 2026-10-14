package io.github.palmeany.mctoolkit.bridge;

import static io.github.palmeany.mctoolkit.bridge.Fakes.answers;
import static io.github.palmeany.mctoolkit.bridge.Fakes.proxy;
import static io.github.palmeany.mctoolkit.bridge.Fakes.stack;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.Arrays;
import java.util.Collections;
import java.util.Map;
import org.bukkit.Material;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.meta.EnchantmentStorageMeta;
import org.junit.jupiter.api.Test;

/** Runs against the 1.8.8 API: no NamespacedKey, no Damageable meta — the legacy paths of every lookup. */
class ItemReaderTest {

    @Test
    void namedEnchantedDamagedSword() {
        ItemStack sword = stack(Material.DIAMOND_SWORD, 1, (short) 12, Fakes.meta("§b§lExcal§xibur"),
                Collections.singletonMap(Enchantment.DAMAGE_ALL, 5));
        Map<String, Object> item = ItemReader.read(sword, 0);
        assertEquals(Arrays.asList("slot", "id", "count", "name", "enchanted", "damage", "max_damage"),
                Arrays.asList(item.keySet().toArray()));
        assertEquals(0, item.get("slot"));
        assertEquals("minecraft:diamond_sword", item.get("id"));
        assertEquals(1, item.get("count"));
        assertEquals("Excalibur", item.get("name"));
        assertEquals(true, item.get("enchanted"));
        assertEquals(12, item.get("damage"));
        assertEquals(1561, item.get("max_damage"));
        assertEquals("{\"slot\":0,\"id\":\"minecraft:diamond_sword\",\"count\":1,\"name\":\"Excalibur\","
                + "\"enchanted\":true,\"damage\":12,\"max_damage\":1561}", Json.write(item));
    }

    @Test
    void plainStackHasNullDamageAndNoSlotWhenNotGiven() {
        Map<String, Object> item = ItemReader.read(stack(Material.COBBLESTONE, 64), null);
        assertFalse(item.containsKey("slot"));
        assertEquals("minecraft:cobblestone", item.get("id"));
        assertEquals(64, item.get("count"));
        assertNull(item.get("name"));
        assertEquals(false, item.get("enchanted"));
        assertTrue(item.containsKey("damage"));
        assertNull(item.get("damage"));
        assertNull(item.get("max_damage"));
    }

    @Test
    void undamagedToolReportsZero() {
        Map<String, Object> item = ItemReader.read(stack(Material.IRON_PICKAXE, 1), 3);
        assertEquals(0, item.get("damage"));
        assertEquals(250, item.get("max_damage"));
    }

    @Test
    void emptySlotsAreSkipped() {
        assertNull(ItemReader.read(null, 1));
        assertNull(ItemReader.read(stack(Material.AIR, 1), 1));
        assertNull(ItemReader.read(stack(Material.STONE, 0), 1));
    }

    @Test
    void storedEnchantmentsCountAsEnchanted() {
        EnchantmentStorageMeta book = proxy(EnchantmentStorageMeta.class, answers("hasStoredEnchants", true));
        Map<String, Object> item = ItemReader.read(stack(Material.ENCHANTED_BOOK, 1, (short) 0, book, null), 7);
        assertEquals(true, item.get("enchanted"));
        assertNull(item.get("name"));
    }

    @Test
    void blankOrColourOnlyNamesAreNull() {
        assertNull(ItemReader.displayName(Fakes.meta("§r")));
        assertNull(ItemReader.displayName(Fakes.meta(null)));
        assertEquals("Plain", ItemReader.displayName(Fakes.meta("Plain")));
    }

    @Test
    void airVariantsAreAir() {
        assertTrue(ItemReader.isAir("AIR"));
        assertTrue(ItemReader.isAir("CAVE_AIR"));
        assertTrue(ItemReader.isAir("VOID_AIR"));
        assertFalse(ItemReader.isAir("STAIRS"));
    }

    @Test
    void legacyIdsAreLowercasedConstantNames() {
        assertEquals("minecraft:wood_sword", Ids.material(Material.WOOD_SWORD));
        assertNull(Ids.keyOf(Material.STONE)); // 1.8.8 has no NamespacedKey
    }
}
