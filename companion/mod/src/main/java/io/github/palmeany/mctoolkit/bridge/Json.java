package io.github.palmeany.mctoolkit.bridge;

import java.math.BigDecimal;
import java.math.RoundingMode;

/** Minimal compact JSON writer: commas are placed automatically, null values are written as null. */
final class Json {

    private final StringBuilder out = new StringBuilder(512);
    private boolean needComma;

    Json beginObject() {
        separator();
        out.append('{');
        needComma = false;
        return this;
    }

    Json endObject() {
        out.append('}');
        needComma = true;
        return this;
    }

    Json beginArray() {
        separator();
        out.append('[');
        needComma = false;
        return this;
    }

    Json endArray() {
        out.append(']');
        needComma = true;
        return this;
    }

    Json name(String name) {
        separator();
        string(name);
        out.append(':');
        needComma = false;
        return this;
    }

    Json value(String value) {
        separator();
        if (value == null) {
            out.append("null");
        } else {
            string(value);
        }
        needComma = true;
        return this;
    }

    Json value(Boolean value) {
        return literal(value == null ? "null" : value.toString());
    }

    /** Whole numbers (ints, longs). */
    Json value(Long value) {
        return literal(value == null ? "null" : value.toString());
    }

    Json value(Integer value) {
        return literal(value == null ? "null" : value.toString());
    }

    /** Doubles, rounded to two decimals as the protocol requires. */
    Json decimal(Double value) {
        return literal(value == null ? "null" : decimal(value.doubleValue()));
    }

    /** Pre-rendered JSON (an already written object). */
    Json raw(String json) {
        return literal(json);
    }

    Json field(String name, String value) {
        return name(name).value(value);
    }

    Json field(String name, Integer value) {
        return name(name).value(value);
    }

    Json field(String name, Long value) {
        return name(name).value(value);
    }

    Json field(String name, Boolean value) {
        return name(name).value(value);
    }

    Json decimalField(String name, Double value) {
        return name(name).decimal(value);
    }

    @Override
    public String toString() {
        return out.toString();
    }

    /** Renders a finite double with at most two decimals and at least one ("20.0", "3.2", "0.42"). */
    static String decimal(double value) {
        if (!Double.isFinite(value)) {
            return "null";
        }
        String text = BigDecimal.valueOf(value).setScale(2, RoundingMode.HALF_UP).toPlainString();
        return text.endsWith("0") ? text.substring(0, text.length() - 1) : text;
    }

    private Json literal(String text) {
        separator();
        out.append(text);
        needComma = true;
        return this;
    }

    private void separator() {
        if (needComma) {
            out.append(',');
        }
    }

    private void string(String value) {
        out.append('"');
        for (int i = 0; i < value.length(); i++) {
            char c = value.charAt(i);
            switch (c) {
                case '"' -> out.append("\\\"");
                case '\\' -> out.append("\\\\");
                case '\n' -> out.append("\\n");
                case '\r' -> out.append("\\r");
                case '\t' -> out.append("\\t");
                case '\b' -> out.append("\\b");
                case '\f' -> out.append("\\f");
                default -> {
                    if (c < 0x20 || c == ' ' || c == ' ') {
                        out.append(String.format("\\u%04x", (int) c));
                    } else {
                        out.append(c);
                    }
                }
            }
        }
        out.append('"');
    }
}
