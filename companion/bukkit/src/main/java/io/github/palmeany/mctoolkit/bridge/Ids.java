package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.HashMap;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Namespaced ids ({@code minecraft:diamond_sword}) for items, entity types, effects and worlds on every version: the
 * {@code Keyed#getKey()} of 1.13+ when the server has it, otherwise a name derived from the legacy enum constant.
 */
final class Ids {

    /** Pre-1.18 PotionEffectType names that differ from the vanilla effect ids. */
    private static final Map<String, String> LEGACY_EFFECTS = new HashMap<>();
    private static final ConcurrentHashMap<Object, String> ENTITY_CACHE = new ConcurrentHashMap<>();

    static {
        LEGACY_EFFECTS.put("SLOW", "slowness");
        LEGACY_EFFECTS.put("FAST_DIGGING", "haste");
        LEGACY_EFFECTS.put("SLOW_DIGGING", "mining_fatigue");
        LEGACY_EFFECTS.put("INCREASE_DAMAGE", "strength");
        LEGACY_EFFECTS.put("HEAL", "instant_health");
        LEGACY_EFFECTS.put("HARM", "instant_damage");
        LEGACY_EFFECTS.put("JUMP", "jump_boost");
        LEGACY_EFFECTS.put("CONFUSION", "nausea");
        LEGACY_EFFECTS.put("DAMAGE_RESISTANCE", "resistance");
        LEGACY_EFFECTS.put("UNLUCK", "unluck");
    }

    private Ids() {
    }

    /** Item id of a {@code Material}: its NamespacedKey, else {@code minecraft:} + the lowercased constant name. */
    static String material(Object material) {
        String key = keyOf(material);
        return key != null ? key : "minecraft:" + lower(String.valueOf(material));
    }

    /** Entity type id: its NamespacedKey (1.14+), the vanilla save id (1.11+), else the lowercased constant name. */
    static String entity(Object type) {
        if (type == null) {
            return "minecraft:unknown";
        }
        String cached = ENTITY_CACHE.get(type);
        if (cached == null) {
            cached = computeEntity(type);
            ENTITY_CACHE.put(type, cached);
        }
        return cached;
    }

    private static String computeEntity(Object type) {
        String key = keyOf(type);
        if (key != null) {
            return key;
        }
        Object name = Reflect.call(type, type.getClass(), "getName");
        if (name instanceof String && ((String) name).matches("[a-z0-9_.\\-]+")) {
            return "minecraft:" + name;
        }
        String constant = String.valueOf(type);
        if ("DROPPED_ITEM".equals(constant)) {
            return "minecraft:item";
        }
        return "minecraft:" + lower(constant);
    }

    /** Effect id: its NamespacedKey (1.18+), else the legacy name translated to the vanilla id. */
    static String effect(Object type) {
        if (type == null) {
            return "minecraft:unknown";
        }
        String key = keyOf(type);
        if (key != null) {
            return key;
        }
        Object name = Reflect.call(type, type.getClass(), "getName");
        String legacy = name instanceof String ? (String) name : String.valueOf(type);
        String modern = LEGACY_EFFECTS.get(legacy.toUpperCase(Locale.ROOT));
        return "minecraft:" + (modern != null ? modern : lower(legacy));
    }

    /** {@code Keyed#getKey()} as {@code namespace:path}, or null when the object has no (valid) key. */
    static String keyOf(Object keyed) {
        if (keyed == null) {
            return null;
        }
        Method getKey = Reflect.method(keyed.getClass(), "getKey");
        if (getKey == null) {
            return null;
        }
        Object key = Reflect.invoke(getKey, keyed);
        if (key == null) {
            return null;
        }
        String text = key.toString();
        if (text.isEmpty()) {
            return null;
        }
        return text.indexOf(':') < 0 ? "minecraft:" + text : text;
    }

    static String lower(String text) {
        return text.toLowerCase(Locale.ROOT);
    }
}
