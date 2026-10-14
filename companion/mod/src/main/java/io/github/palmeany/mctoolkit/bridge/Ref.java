package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Constructor;
import java.lang.reflect.Field;
import java.lang.reflect.InvocationTargetException;
import java.lang.reflect.Method;
import java.lang.reflect.Modifier;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

/**
 * One reflected Minecraft member (method, field or constructor), resolved once at startup. Calls
 * through {@link #get}/{@link #call} never throw: a missing member or a failing call yields null and
 * the failure is logged once per member.
 */
final class Ref {

    /** Human-readable Mojang signature, e.g. {@code ServerPlayer#connection}. */
    final String key;
    private final Method method;
    private final Field field;
    private final Constructor<?> constructor;

    private Ref(String key, Method method, Field field, Constructor<?> constructor) {
        this.key = key;
        this.method = method;
        this.field = field;
        this.constructor = constructor;
    }

    boolean ok() {
        return method != null || field != null || constructor != null;
    }

    /** Field type or method return type, null for constructors and missing members. */
    Class<?> valueType() {
        return method != null ? method.getReturnType() : field != null ? field.getType() : null;
    }

    /** Reads a field / calls a no-arg method; null when missing or failing. Static: target null. */
    Object get(Object target) {
        return call(target);
    }

    /** Calls the method (or reads the field) and returns null on any failure. */
    Object call(Object target, Object... args) {
        if (!ok()) {
            return null;
        }
        try {
            return invoke(target, args);
        } catch (Throwable t) {
            Log.warnOnce("call:" + key, "Reading " + key + " failed; the field it feeds stays null (" + t + ")", null);
            return null;
        }
    }

    /** Like {@link #call} but throws, for code that wants to know why (the dev self-test). */
    Object invoke(Object target, Object... args) throws Throwable {
        try {
            if (method != null) {
                return method.invoke(target, args);
            }
            if (field != null) {
                return field.get(target);
            }
            if (constructor != null) {
                return constructor.newInstance(args);
            }
            throw new IllegalStateException(key + " is not available on this server");
        } catch (InvocationTargetException e) {
            throw e.getCause() != null ? e.getCause() : e;
        }
    }

    /** Sets a field (dev self-test only). */
    void set(Object target, Object value) throws IllegalAccessException {
        if (field == null) {
            throw new IllegalStateException(key + " is not available on this server");
        }
        field.set(target, value);
    }

    /** Resolves members by Mojang name through the runtime's {@link Names} and remembers all of them. */
    static final class Resolver {
        private final Names names;
        final List<Ref> all = new ArrayList<>();

        Resolver(Names names) {
            this.names = names;
        }

        Ref method(String owner, String name, String... params) {
            String key = simple(owner) + "#" + name + "(" + simpleList(params) + ")";
            Class<?> type = names.type(owner);
            Class<?>[] types = types(params);
            Method found = null;
            if (type != null && types != null) {
                for (String runtime : names.methodNames(owner, name, Arrays.asList(params))) {
                    found = findMethod(type, runtime, types);
                    if (found != null) {
                        break;
                    }
                }
            }
            return remember(new Ref(key, found, null, null));
        }

        Ref field(String owner, String name) {
            String key = simple(owner) + "#" + name;
            Class<?> type = names.type(owner);
            Field found = null;
            if (type != null) {
                for (String runtime : names.fieldNames(owner, name)) {
                    found = findField(type, runtime);
                    if (found != null) {
                        break;
                    }
                }
            }
            return remember(new Ref(key, null, found, null));
        }

        Ref constructor(String owner, String... params) {
            String key = simple(owner) + "#<init>(" + simpleList(params) + ")";
            Class<?> type = names.type(owner);
            Class<?>[] types = types(params);
            Constructor<?> found = null;
            if (type != null && types != null) {
                try {
                    found = type.getDeclaredConstructor(types);
                    found.trySetAccessible();
                } catch (NoSuchMethodException | LinkageError | SecurityException ignored) {
                    found = null;
                }
            }
            return remember(new Ref(key, null, null, found));
        }

        /** Value of a static field (registries, component types), null when unavailable. */
        static Object staticValue(Ref field) {
            if (!field.ok()) {
                return null;
            }
            if (field.field != null && !Modifier.isStatic(field.field.getModifiers())) {
                return null;
            }
            return field.call(null);
        }

        private Ref remember(Ref ref) {
            all.add(ref);
            return ref;
        }

        private Class<?>[] types(String[] params) {
            Class<?>[] out = new Class<?>[params.length];
            for (int i = 0; i < params.length; i++) {
                out[i] = names.type(params[i]);
                if (out[i] == null) {
                    return null;
                }
            }
            return out;
        }

        private static Method findMethod(Class<?> owner, String name, Class<?>[] params) {
            try {
                Method m = owner.getMethod(name, params);
                m.trySetAccessible();
                return m;
            } catch (NoSuchMethodException | LinkageError | SecurityException ignored) {
                // not public or not there: walk the declared members
            }
            for (Class<?> c = owner; c != null; c = c.getSuperclass()) {
                try {
                    Method m = c.getDeclaredMethod(name, params);
                    m.trySetAccessible();
                    return m;
                } catch (NoSuchMethodException | LinkageError | SecurityException ignored) {
                    // keep walking up
                }
            }
            return null;
        }

        private static Field findField(Class<?> owner, String name) {
            for (Class<?> c = owner; c != null; c = c.getSuperclass()) {
                try {
                    Field f = c.getDeclaredField(name);
                    if (!Modifier.isPublic(f.getModifiers()) || !Modifier.isPublic(c.getModifiers())) {
                        if (!f.trySetAccessible()) {
                            return null;
                        }
                    }
                    return f;
                } catch (NoSuchFieldException | LinkageError | SecurityException ignored) {
                    // keep walking up
                }
            }
            return null;
        }

        private static String simple(String name) {
            return name.substring(name.lastIndexOf('.') + 1);
        }

        private static String simpleList(String[] params) {
            StringBuilder out = new StringBuilder();
            for (String p : params) {
                if (out.length() > 0) {
                    out.append(',');
                }
                out.append(simple(p));
            }
            return out.toString();
        }
    }
}
