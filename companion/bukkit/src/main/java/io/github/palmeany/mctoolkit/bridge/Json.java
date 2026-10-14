package io.github.palmeany.mctoolkit.bridge;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.Collection;
import java.util.Iterator;
import java.util.Map;

/**
 * Compact JSON serialisation of plain Java trees: {@link Map} (insertion order is kept, so use LinkedHashMap),
 * {@link Collection}, String, Boolean, integral numbers, doubles and null. Doubles are rounded to two decimals as the
 * protocol asks and always keep a fractional part ({@code 20.0}), so readers see a float; NaN and infinities become
 * {@code null}.
 */
final class Json {

    private static final char[] HEX = "0123456789abcdef".toCharArray();

    private Json() {
    }

    static String write(Object value) {
        StringBuilder out = new StringBuilder(256);
        append(out, value);
        return out.toString();
    }

    static void append(StringBuilder out, Object value) {
        if (value == null) {
            out.append("null");
        } else if (value instanceof String) {
            string(out, (String) value);
        } else if (value instanceof Boolean) {
            out.append(((Boolean) value).booleanValue() ? "true" : "false");
        } else if (value instanceof Double || value instanceof Float) {
            out.append(number(((Number) value).doubleValue()));
        } else if (value instanceof Number) {
            out.append(((Number) value).longValue());
        } else if (value instanceof Map) {
            out.append('{');
            Iterator<? extends Map.Entry<?, ?>> entries = ((Map<?, ?>) value).entrySet().iterator();
            while (entries.hasNext()) {
                Map.Entry<?, ?> entry = entries.next();
                string(out, String.valueOf(entry.getKey()));
                out.append(':');
                append(out, entry.getValue());
                if (entries.hasNext()) {
                    out.append(',');
                }
            }
            out.append('}');
        } else if (value instanceof Collection) {
            out.append('[');
            Iterator<?> items = ((Collection<?>) value).iterator();
            while (items.hasNext()) {
                append(out, items.next());
                if (items.hasNext()) {
                    out.append(',');
                }
            }
            out.append(']');
        } else {
            string(out, String.valueOf(value));
        }
    }

    /** A double rounded half-up to two decimals in plain notation, e.g. {@code 19.98}, {@code 20.0}, {@code -307.25}. */
    static String number(double value) {
        if (Double.isNaN(value) || Double.isInfinite(value)) {
            return "null";
        }
        String text = BigDecimal.valueOf(value).setScale(2, RoundingMode.HALF_UP).stripTrailingZeros().toPlainString();
        return text.indexOf('.') < 0 ? text + ".0" : text;
    }

    /** {@code value} rounded half-up to two decimals, null for null/NaN/infinite. */
    static Double round(Double value) {
        if (value == null || value.isNaN() || value.isInfinite()) {
            return null;
        }
        return BigDecimal.valueOf(value).setScale(2, RoundingMode.HALF_UP).doubleValue();
    }

    private static void string(StringBuilder out, String text) {
        out.append('"');
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            switch (c) {
                case '"':
                    out.append("\\\"");
                    break;
                case '\\':
                    out.append("\\\\");
                    break;
                case '\n':
                    out.append("\\n");
                    break;
                case '\r':
                    out.append("\\r");
                    break;
                case '\t':
                    out.append("\\t");
                    break;
                case '\b':
                    out.append("\\b");
                    break;
                case '\f':
                    out.append("\\f");
                    break;
                default:
                    if (Character.isSurrogate(c) && !pairedSurrogate(text, i)) {
                        // a lone surrogate is not valid UTF-8, and PHP's json_decode rejects even its \\u escape
                        out.append('�');
                    } else if (c < 0x20 || c == ' ' || c == ' ') {
                        out.append("\\u").append(HEX[(c >> 12) & 15]).append(HEX[(c >> 8) & 15])
                                .append(HEX[(c >> 4) & 15]).append(HEX[c & 15]);
                    } else {
                        out.append(c);
                    }
            }
        }
        out.append('"');
    }

    private static boolean pairedSurrogate(String text, int index) {
        char c = text.charAt(index);
        if (Character.isHighSurrogate(c)) {
            return index + 1 < text.length() && Character.isLowSurrogate(text.charAt(index + 1));
        }
        return index > 0 && Character.isHighSurrogate(text.charAt(index - 1));
    }
}
