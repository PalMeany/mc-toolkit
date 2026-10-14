package io.github.palmeany.mctoolkit.bridge.selftest;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * A strict RFC 8259 JSON parser for checking the bridge's output (objects → LinkedHashMap, arrays → ArrayList,
 * integers → Long, other numbers → Double). Rejects trailing garbage, single quotes, NaN, comments, raw control
 * characters and unpaired surrogates — anything PHP's json_decode would refuse.
 */
public final class MiniJson {

    private final String text;
    private int pos;

    private MiniJson(String text) {
        this.text = text;
    }

    public static Object parse(String text) {
        MiniJson parser = new MiniJson(text);
        parser.whitespace();
        Object value = parser.value();
        parser.whitespace();
        if (parser.pos != text.length()) {
            throw parser.error("trailing characters");
        }
        return value;
    }

    @SuppressWarnings("unchecked")
    public static Map<String, Object> object(String text) {
        Object value = parse(text);
        if (!(value instanceof Map)) {
            throw new IllegalArgumentException("not a JSON object");
        }
        return (Map<String, Object>) value;
    }

    private Object value() {
        if (pos >= text.length()) {
            throw error("unexpected end");
        }
        char c = text.charAt(pos);
        switch (c) {
            case '{':
                return object();
            case '[':
                return array();
            case '"':
                return string();
            case 't':
                return literal("true", Boolean.TRUE);
            case 'f':
                return literal("false", Boolean.FALSE);
            case 'n':
                return literal("null", null);
            default:
                if (c == '-' || (c >= '0' && c <= '9')) {
                    return number();
                }
                throw error("unexpected character '" + c + "'");
        }
    }

    private Map<String, Object> object() {
        Map<String, Object> map = new LinkedHashMap<>();
        pos++;
        whitespace();
        if (peek('}')) {
            pos++;
            return map;
        }
        while (true) {
            whitespace();
            if (!peek('"')) {
                throw error("expected a key");
            }
            String key = string();
            if (map.containsKey(key)) {
                throw error("duplicate key " + key);
            }
            whitespace();
            expect(':');
            whitespace();
            map.put(key, value());
            whitespace();
            if (peek(',')) {
                pos++;
                continue;
            }
            expect('}');
            return map;
        }
    }

    private List<Object> array() {
        List<Object> list = new ArrayList<>();
        pos++;
        whitespace();
        if (peek(']')) {
            pos++;
            return list;
        }
        while (true) {
            whitespace();
            list.add(value());
            whitespace();
            if (peek(',')) {
                pos++;
                continue;
            }
            expect(']');
            return list;
        }
    }

    private String string() {
        expect('"');
        StringBuilder out = new StringBuilder();
        while (true) {
            if (pos >= text.length()) {
                throw error("unterminated string");
            }
            char c = text.charAt(pos++);
            if (c == '"') {
                break;
            }
            if (c < 0x20) {
                throw error("raw control character in string");
            }
            if (c == '\\') {
                if (pos >= text.length()) {
                    throw error("bad escape");
                }
                char e = text.charAt(pos++);
                switch (e) {
                    case '"': out.append('"'); break;
                    case '\\': out.append('\\'); break;
                    case '/': out.append('/'); break;
                    case 'b': out.append('\b'); break;
                    case 'f': out.append('\f'); break;
                    case 'n': out.append('\n'); break;
                    case 'r': out.append('\r'); break;
                    case 't': out.append('\t'); break;
                    case 'u':
                        if (pos + 4 > text.length()) {
                            throw error("bad unicode escape");
                        }
                        out.append((char) Integer.parseInt(text.substring(pos, pos + 4), 16));
                        pos += 4;
                        break;
                    default:
                        throw error("bad escape \\" + e);
                }
            } else {
                out.append(c);
            }
        }
        String result = out.toString();
        for (int i = 0; i < result.length(); i++) {
            char c = result.charAt(i);
            if (Character.isHighSurrogate(c) && (i + 1 >= result.length() || !Character.isLowSurrogate(result.charAt(i + 1)))) {
                throw error("unpaired surrogate");
            }
            if (Character.isLowSurrogate(c) && (i == 0 || !Character.isHighSurrogate(result.charAt(i - 1)))) {
                throw error("unpaired surrogate");
            }
        }
        return result;
    }

    private Object number() {
        int start = pos;
        if (peek('-')) {
            pos++;
        }
        if (peek('0')) {
            pos++;
        } else {
            digits();
        }
        boolean integral = true;
        if (peek('.')) {
            integral = false;
            pos++;
            digits();
        }
        if (peek('e') || peek('E')) {
            integral = false;
            pos++;
            if (peek('+') || peek('-')) {
                pos++;
            }
            digits();
        }
        String number = text.substring(start, pos);
        return integral ? (Object) Long.valueOf(number) : (Object) Double.valueOf(number);
    }

    private void digits() {
        int start = pos;
        while (pos < text.length() && Character.isDigit(text.charAt(pos))) {
            pos++;
        }
        if (pos == start) {
            throw error("expected digits");
        }
    }

    private Object literal(String word, Object value) {
        if (!text.startsWith(word, pos)) {
            throw error("expected " + word);
        }
        pos += word.length();
        return value;
    }

    private void whitespace() {
        while (pos < text.length()) {
            char c = text.charAt(pos);
            if (c == ' ' || c == '\t' || c == '\n' || c == '\r') {
                pos++;
            } else {
                return;
            }
        }
    }

    private boolean peek(char c) {
        return pos < text.length() && text.charAt(pos) == c;
    }

    private void expect(char c) {
        if (!peek(c)) {
            throw error("expected '" + c + "'");
        }
        pos++;
    }

    private IllegalArgumentException error(String message) {
        return new IllegalArgumentException(message + " at " + pos);
    }
}
