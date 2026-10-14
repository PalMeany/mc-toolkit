package io.github.palmeany.mctoolkit.bridge;

import static io.github.palmeany.mctoolkit.bridge.Fakes.answers;
import static io.github.palmeany.mctoolkit.bridge.Fakes.proxy;
import static io.github.palmeany.mctoolkit.bridge.Fakes.stack;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;

import io.github.palmeany.mctoolkit.bridge.selftest.MiniJson;
import java.util.Arrays;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.function.Function;
import org.bukkit.GameMode;
import org.bukkit.Location;
import org.bukkit.Material;
import org.bukkit.World;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.entity.Player;
import org.bukkit.inventory.Inventory;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.PlayerInventory;
import org.bukkit.potion.PotionEffect;
import org.bukkit.potion.PotionEffectType;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

class PlayerReaderTest {

    static final UUID ID = UUID.fromString("0C9A1C4E-5D0A-4B9E-9A51-6B1F3C2A7D10");

    @BeforeAll
    static void registry() {
        Fakes.init();
    }

    /** Stand-in for CraftPlayer#getHandle(): the 1.8 – 1.16 ping lives in a public int field of the server player. */
    public interface HasHandle {
        Object getHandle();
    }

    public static final class ServerPlayer {
        public int ping = 87;
    }

    static World world(String name, World.Environment environment) {
        return proxy(World.class, answers("getName", name, "getEnvironment", environment));
    }

    static Player player(Map<String, Function<Object[], Object>> extra, Class<?>... more) {
        World nether = world("world_nether", World.Environment.NETHER);
        ItemStack[] slots = new ItemStack[36];
        slots[0] = stack(Material.DIAMOND_SWORD, 1, (short) 12, Fakes.meta("Excalibur"),
                Collections.singletonMap(Enchantment.DAMAGE_ALL, 5));
        slots[9] = stack(Material.COBBLESTONE, 64);
        slots[35] = stack(Material.AIR, 1);
        PlayerInventory inventory = proxy(PlayerInventory.class, answers(
                "getItem", (Function<Object[], Object>) args -> slots[(Integer) args[0]],
                "getHelmet", stack(Material.DIAMOND_HELMET, 1),
                "getChestplate", null,
                "getLeggings", stack(Material.AIR, 1),
                "getBoots", stack(Material.DIAMOND_BOOTS, 1, (short) 3, null, null)));
        ItemStack[] ender = new ItemStack[27];
        ender[4] = stack(Material.ENDER_PEARL, 16);
        Inventory enderChest = proxy(Inventory.class, answers(
                "getSize", 27,
                "getItem", (Function<Object[], Object>) args -> ender[(Integer) args[0]]));
        Map<String, Function<Object[], Object>> answers = answers(
                "getUniqueId", ID,
                "getName", "Steve",
                "getGameMode", GameMode.SURVIVAL,
                "getLocation", new Location(nether, 156.5, 70.0, -307.254),
                "getHealth", 18.5,
                "getMaxHealth", 20.0,
                "getFoodLevel", 17,
                "getSaturation", 3.2f,
                "getLevel", 30,
                "getExp", 0.42f,
                "isOp", false,
                "getInventory", inventory,
                "getEnderChest", enderChest,
                "getActivePotionEffects", Arrays.asList(
                        new PotionEffect(PotionEffectType.SPEED, 600, 1),
                        new PotionEffect(PotionEffectType.INCREASE_DAMAGE, Integer.MAX_VALUE, 0),
                        new PotionEffect(PotionEffectType.INVISIBILITY, 20, 0)));
        answers.putAll(extra);
        return proxy(Player.class, answers, more);
    }

    @Test
    void stateFieldsFollowTheProtocol() {
        PlayerSnapshot snapshot = PlayerReader.read(player(new HashMap<>()), 1791950000L);
        Map<String, Object> state = snapshot.state();
        assertEquals(Arrays.asList("name", "mode", "world", "dimension", "x", "y", "z", "health", "max_health", "food",
                "saturation", "level", "exp", "ping", "op", "joined_at"), Arrays.asList(state.keySet().toArray()));
        assertEquals("0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10", snapshot.id());
        assertEquals("{\"name\":\"Steve\",\"mode\":\"survival\",\"world\":\"world_nether\",\"dimension\":\"nether\","
                + "\"x\":156.5,\"y\":70.0,\"z\":-307.25,\"health\":18.5,\"max_health\":20.0,\"food\":17,"
                + "\"saturation\":3.2,\"level\":30,\"exp\":0.42,\"ping\":null,\"op\":false,\"joined_at\":1791950000}",
                Json.write(state));
        assertEquals("{\"uuid\":\"0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10\",\"name\":\"Steve\",\"world\":\"world_nether\","
                + "\"dimension\":\"nether\",\"x\":156.5,\"y\":70.0,\"z\":-307.25}", Json.write(snapshot.pin()));
    }

    @Test
    void detailListsItemsArmorEnderChestAndEffects() {
        PlayerSnapshot snapshot = PlayerReader.read(player(new HashMap<>()), null);
        Map<String, Object> detail = MiniJson.object(Json.write(snapshot.detail));
        assertEquals(Arrays.asList("uuid", "name", "inventory", "armor", "offhand", "ender_chest", "effects"),
                Arrays.asList(detail.keySet().toArray()));

        List<?> inventory = (List<?>) detail.get("inventory");
        assertEquals(2, inventory.size()); // slot 35 holds air
        assertEquals("{slot=0, id=minecraft:diamond_sword, count=1, name=Excalibur, enchanted=true, damage=12, "
                + "max_damage=1561}", inventory.get(0).toString());
        assertEquals(9L, ((Map<?, ?>) inventory.get(1)).get("slot"));

        Map<?, ?> armor = (Map<?, ?>) detail.get("armor");
        assertEquals(Arrays.asList("head", "chest", "legs", "feet"), Arrays.asList(armor.keySet().toArray()));
        assertEquals("minecraft:diamond_helmet", ((Map<?, ?>) armor.get("head")).get("id"));
        assertNull(armor.get("chest"));
        assertNull(armor.get("legs"));
        assertEquals(3L, ((Map<?, ?>) armor.get("feet")).get("damage"));
        assertEquals(429L, ((Map<?, ?>) armor.get("feet")).get("max_damage"));

        assertNull(detail.get("offhand")); // the 1.8.8 API has no offhand

        List<?> ender = (List<?>) detail.get("ender_chest");
        assertEquals(1, ender.size());
        assertEquals("{slot=4, id=minecraft:ender_pearl, count=16, name=null, enchanted=false, damage=null, "
                + "max_damage=null}", ender.get(0).toString());

        assertEquals("[{id=minecraft:invisibility, amplifier=0, duration=20}, "
                + "{id=minecraft:speed, amplifier=1, duration=600}, "
                + "{id=minecraft:strength, amplifier=0, duration=-1}]", detail.get("effects").toString());
    }

    @Test
    void pingFromTheServerPlayerFieldOnOldServers() {
        Map<String, Function<Object[], Object>> extra = answers("getHandle", new ServerPlayer());
        assertEquals(87, PlayerReader.read(player(extra, HasHandle.class), null).ping);
    }

    @Test
    void failingGettersOnlyNullTheirOwnField() {
        Map<String, Function<Object[], Object>> extra = answers(
                "getHealth", (Function<Object[], Object>) args -> {
                    throw new UnsupportedOperationException("plugin fake");
                },
                "getInventory", (Function<Object[], Object>) args -> {
                    throw new IllegalStateException("off thread");
                });
        PlayerSnapshot snapshot = PlayerReader.read(player(extra), null);
        assertNull(snapshot.health);
        assertEquals(20.0, snapshot.maxHealth);
        assertEquals("[]", snapshot.detail.get("inventory").toString());
        assertEquals("{head=null, chest=null, legs=null, feet=null}", snapshot.detail.get("armor").toString());
        assertEquals(1, ((List<?>) snapshot.detail.get("ender_chest")).size());
    }

    @Test
    void dimensionsByEnvironment() {
        assertEquals("overworld", Worlds.dimension(world("world", World.Environment.NORMAL)));
        assertEquals("nether", Worlds.dimension(world("world_nether", World.Environment.NETHER)));
        assertEquals("end", Worlds.dimension(world("world_the_end", World.Environment.THE_END)));
    }
}
