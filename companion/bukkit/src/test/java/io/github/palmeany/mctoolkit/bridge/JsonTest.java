package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;

import io.github.palmeany.mctoolkit.bridge.selftest.MiniJson;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;

@SuppressWarnings("unchecked")
class JsonTest {

    @Test
    void doublesAreRoundedToTwoDecimalsAndStayFloats() {
        assertEquals("19.98", Json.number(19.976));
        assertEquals("20.0", Json.number(20));
        assertEquals("-307.25", Json.number(-307.25));
        assertEquals("0.3", Json.number(0.1 + 0.2));
        assertEquals("2.68", Json.number(2.675)); // half-up on the decimal value, not the binary one
        assertEquals("0.42", Json.number(0.42f));
        assertEquals("0.0", Json.number(-0.001));
        assertEquals("29999984.5", Json.number(29_999_984.5)); // never exponent notation
        assertEquals("0.0", Json.number(0.0001));
        assertEquals("1.0E-4", Double.toString(0.0001)); // what a naive writer would have produced
        assertEquals("null", Json.number(Double.NaN));
        assertEquals("null", Json.number(Double.POSITIVE_INFINITY));
    }

    @Test
    void roundKeepsNullAndRounds() {
        assertNull(Json.round(null));
        assertNull(Json.round(Double.NaN));
        assertEquals(3.2, Json.round((double) 3.2f));
        assertEquals(156.5, Json.round(156.499));
    }

    @Test
    void integersBooleansAndNull() {
        assertEquals("[1,-2,1791956941,true,false,null]",
                Json.write(Arrays.asList(1, -2, 1791956941L, true, false, null)));
    }

    @Test
    void mapsKeepInsertionOrderAndNullValues() {
        Map<String, Object> map = new LinkedHashMap<>();
        map.put("b", 1);
        map.put("a", null);
        map.put("c", Collections.singletonMap("x", new ArrayList<>()));
        assertEquals("{\"b\":1,\"a\":null,\"c\":{\"x\":[]}}", Json.write(map));
        assertEquals("{}", Json.write(new LinkedHashMap<>()));
    }

    @Test
    void stringsAreEscaped() {
        String nasty = "q\"b\\s/\n\r\t\b\f\u0001\u001f é ✓ \ud83d\ude00 \u2028";
        String json = Json.write(nasty);
        assertEquals("\"q\\\"b\\\\s/\\n\\r\\t\\b\\f\\u0001\\u001f é ✓ \ud83d\ude00 \\u2028\"", json);
        assertEquals(nasty, MiniJson.parse(json));
    }

    @Test
    void loneSurrogatesBecomeReplacementCharacters() {
        String json = Json.write("a\ud83db\ude00c");
        assertEquals("\"a\ufffdb\ufffdc\"", json);
        MiniJson.parse(json); // valid: PHP's json_decode would reject a \\ud83d escape
    }

    @Test
    void outputParsesStrictly() {
        Map<String, Object> map = new LinkedHashMap<>();
        map.put("tps", 19.98);
        map.put("list", Arrays.asList(1.5, "x", null, Collections.emptyMap()));
        Map<String, Object> parsed = MiniJson.object(Json.write(map));
        assertEquals(19.98, parsed.get("tps"));
        assertEquals(Arrays.asList(1.5, "x", null, Collections.emptyMap()), parsed.get("list"));
    }

    @Test
    void miniJsonRejectsWhatPhpRejects() {
        for (String bad : new String[] {"{'a':1}", "[1,]", "{\"a\":1}x", "NaN", "\"\\ud83d\"", "{\"a\":1,\"a\":2}", "\"\u0001\""}) {
            assertThrows(IllegalArgumentException.class, () -> MiniJson.parse(bad), bad);
        }
        List<Object> ok = (List<Object>) MiniJson.parse(" [ -1 , 2.5e3 , 0 ] ");
        assertEquals(Arrays.asList(-1L, 2500.0, 0L), ok);
    }
}
