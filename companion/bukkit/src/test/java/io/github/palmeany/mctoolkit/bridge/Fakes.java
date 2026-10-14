package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.lang.reflect.Proxy;
import java.util.Arrays;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.function.Function;
import org.bukkit.Material;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.meta.ItemMeta;
import org.bukkit.potion.PotionEffectType;

/** java.lang.reflect.Proxy stand-ins for Bukkit interfaces, plus an ItemStack that needs no running server. */
final class Fakes {

    static {
        // The 1.8.8 API resolves PotionEffectType names through a registry the server fills; do it for the tests.
        register(1, "SPEED");
        register(5, "INCREASE_DAMAGE");
        register(14, "INVISIBILITY");
    }

    private Fakes() {
    }

    static void init() {
        // forces the static registration above
    }

    private static void register(int id, String name) {
        PotionEffectType.registerPotionEffectType(new PotionEffectType(id) {
            @Override
            public double getDurationModifier() {
                return 1;
            }

            @Override
            public String getName() {
                return name;
            }

            @Override
            public boolean isInstant() {
                return false;
            }
        });
    }

    /**
     * A proxy implementing {@code types}; {@code answers} maps method names to a function of the call arguments,
     * everything else answers the type's zero value (false, 0, null).
     */
    @SuppressWarnings("unchecked")
    static <T> T proxy(Class<T> main, Map<String, Function<Object[], Object>> answers, Class<?>... more) {
        Class<?>[] types = new Class<?>[more.length + 1];
        types[0] = main;
        System.arraycopy(more, 0, types, 1, more.length);
        return (T) Proxy.newProxyInstance(Fakes.class.getClassLoader(), types, (self, method, args) -> {
            Function<Object[], Object> answer = answers.get(method.getName());
            if (answer != null) {
                return answer.apply(args == null ? new Object[0] : args);
            }
            switch (method.getName()) {
                case "equals":
                    return self == args[0];
                case "hashCode":
                    return System.identityHashCode(self);
                case "toString":
                    return "Fake" + main.getSimpleName();
                default:
                    return zero(method);
            }
        });
    }

    static Map<String, Function<Object[], Object>> answers(Object... pairs) {
        Map<String, Function<Object[], Object>> answers = new HashMap<>();
        for (int i = 0; i < pairs.length; i += 2) {
            Object value = pairs[i + 1];
            if (value instanceof Function) {
                @SuppressWarnings("unchecked")
                Function<Object[], Object> function = (Function<Object[], Object>) value;
                answers.put((String) pairs[i], function);
            } else {
                answers.put((String) pairs[i], args -> value);
            }
        }
        return answers;
    }

    static Object zero(Method method) {
        Class<?> type = method.getReturnType();
        if (type == boolean.class) {
            return false;
        }
        if (type == int.class) {
            return 0;
        }
        if (type == long.class) {
            return 0L;
        }
        if (type == double.class) {
            return 0d;
        }
        if (type == float.class) {
            return 0f;
        }
        if (type == short.class) {
            return (short) 0;
        }
        if (type == byte.class) {
            return (byte) 0;
        }
        if (type == char.class) {
            return '\0';
        }
        if (type == List.class) {
            return Collections.emptyList();
        }
        return null;
    }

    /** Item meta with an optional display name. */
    static ItemMeta meta(String displayName) {
        return proxy(ItemMeta.class, answers(
                "hasDisplayName", displayName != null,
                "getDisplayName", displayName));
    }

    /** An ItemStack whose meta and enchantments are given directly (the 1.8.8 API would ask a running server). */
    static ItemStack stack(Material type, int amount, short durability, ItemMeta meta, Map<Enchantment, Integer> enchantments) {
        return new TestStack(type, amount, durability, meta, enchantments);
    }

    static ItemStack stack(Material type, int amount) {
        return stack(type, amount, (short) 0, null, null);
    }

    static final class TestStack extends ItemStack {
        private final ItemMeta meta;
        private final Map<Enchantment, Integer> enchantments;

        TestStack(Material type, int amount, short durability, ItemMeta meta, Map<Enchantment, Integer> enchantments) {
            super(type, amount, durability);
            this.meta = meta;
            this.enchantments = enchantments == null ? Collections.<Enchantment, Integer>emptyMap() : enchantments;
        }

        @Override
        public boolean hasItemMeta() {
            return meta != null;
        }

        @Override
        public ItemMeta getItemMeta() {
            return meta;
        }

        @Override
        public Map<Enchantment, Integer> getEnchantments() {
            return enchantments;
        }
    }

    static ItemStack[] slots(ItemStack... items) {
        return Arrays.copyOf(items, items.length);
    }
}
