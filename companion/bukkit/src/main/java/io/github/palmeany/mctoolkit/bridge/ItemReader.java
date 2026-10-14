package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.LinkedHashMap;
import java.util.Map;
import org.bukkit.Material;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.meta.EnchantmentStorageMeta;
import org.bukkit.inventory.meta.ItemMeta;

/**
 * One item as the protocol describes it: {@code {"slot"?, "id", "count", "name", "enchanted", "damage",
 * "max_damage"}}. Each part is read defensively so that one odd item (a modded material on a hybrid, a broken meta)
 * degrades to nulls instead of losing the whole file.
 */
final class ItemReader {

    private static final String DAMAGEABLE_META = "org.bukkit.inventory.meta.Damageable";

    private ItemReader() {
    }

    /** The item, or null for an empty slot (null, air or a count of zero). {@code slot} is left out when null. */
    static Map<String, Object> read(ItemStack stack, Integer slot) {
        if (stack == null) {
            return null;
        }
        Object type;
        int amount;
        try {
            type = stack.getType();
            amount = stack.getAmount();
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
        if (type == null || amount <= 0 || isAir(type)) {
            return null;
        }
        Map<String, Object> item = new LinkedHashMap<>();
        if (slot != null) {
            item.put("slot", slot);
        }
        item.put("id", Ids.material(type));
        item.put("count", amount);
        ItemMeta meta = meta(stack);
        item.put("name", displayName(meta));
        item.put("enchanted", enchanted(stack, meta));
        Integer maxDamage = maxDamage(type, meta);
        item.put("damage", maxDamage == null ? null : damage(stack, meta));
        item.put("max_damage", maxDamage);
        return item;
    }

    static boolean isAir(Object type) {
        String name = String.valueOf(type);
        return name.equals("AIR") || name.endsWith("_AIR");
    }

    private static ItemMeta meta(ItemStack stack) {
        try {
            return stack.hasItemMeta() ? stack.getItemMeta() : null;
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
    }

    /** The custom display name as plain text (legacy colour codes stripped), null when there is none. */
    static String displayName(ItemMeta meta) {
        if (meta == null) {
            return null;
        }
        try {
            if (!meta.hasDisplayName()) {
                return null;
            }
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
        String raw;
        try {
            raw = meta.getDisplayName();
        } catch (RuntimeException | LinkageError e) {
            raw = adventurePlain(Reflect.call(meta, ItemMeta.class, "displayName"));
        }
        String plain = Text.stripLegacy(raw);
        return plain == null || plain.isEmpty() ? null : plain;
    }

    /** Paper's Adventure component flattened with PlainTextComponentSerializer, for servers without legacy names. */
    private static String adventurePlain(Object component) {
        if (component == null) {
            return null;
        }
        Class<?> serializer = Reflect.type("net.kyori.adventure.text.serializer.plain.PlainTextComponentSerializer");
        Object instance = Reflect.invoke(Reflect.method(serializer, "plainText"), null);
        Class<?> componentType = Reflect.type("net.kyori.adventure.text.Component");
        Method serialize = Reflect.method(serializer, "serialize", componentType);
        Object text = Reflect.invoke(serialize, instance, component);
        return text == null ? null : text.toString();
    }

    private static boolean enchanted(ItemStack stack, ItemMeta meta) {
        try {
            Map<?, ?> enchantments = stack.getEnchantments();
            if (enchantments != null && !enchantments.isEmpty()) {
                return true;
            }
        } catch (RuntimeException | LinkageError ignored) {
            // fall through to the stored enchantments
        }
        try {
            return meta instanceof EnchantmentStorageMeta && ((EnchantmentStorageMeta) meta).hasStoredEnchants();
        } catch (RuntimeException | LinkageError e) {
            return false;
        }
    }

    /** Durability of the item: a per-item max_damage component (1.20.5+) or the material's, null if undamageable. */
    private static Integer maxDamage(Object type, ItemMeta meta) {
        Class<?> damageable = Reflect.type(DAMAGEABLE_META);
        if (meta != null && damageable != null && damageable.isInstance(meta)
                && Boolean.TRUE.equals(Reflect.call(meta, damageable, "hasMaxDamage"))) {
            Object max = Reflect.call(meta, damageable, "getMaxDamage");
            if (max instanceof Number && ((Number) max).intValue() > 0) {
                return ((Number) max).intValue();
            }
        }
        try {
            if (type instanceof Material) {
                short max = ((Material) type).getMaxDurability();
                return max > 0 ? Integer.valueOf(max) : null;
            }
        } catch (RuntimeException | LinkageError ignored) {
            // unknown durability
        }
        return null;
    }

    /** Damage taken: the Damageable meta on 1.13+ (no meta means undamaged), the item durability before that. */
    private static Integer damage(ItemStack stack, ItemMeta meta) {
        Class<?> damageable = Reflect.type(DAMAGEABLE_META);
        if (damageable != null) {
            if (meta == null || !damageable.isInstance(meta)) {
                return 0;
            }
            Object damage = Reflect.call(meta, damageable, "getDamage");
            return damage instanceof Number ? ((Number) damage).intValue() : null;
        }
        Object durability = Reflect.call(stack, ItemStack.class, "getDurability");
        return durability instanceof Number ? ((Number) durability).intValue() : null;
    }
}
