package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;

import org.junit.jupiter.api.Test;

class JsonTest {

    @Test
    void decimalsHaveAtMostTwoAndAtLeastOneFractionDigit() {
        assertEquals("20.0", Json.decimal(20));
        assertEquals("3.2", Json.decimal(3.2));
        assertEquals("156.5", Json.decimal(156.5));
        assertEquals("-307.25", Json.decimal(-307.25));
        assertEquals("0.42", Json.decimal(0.4242));
        assertEquals("0.43", Json.decimal(0.425));
        assertEquals("19.98", Json.decimal(19.976));
        assertEquals("0.0", Json.decimal(-0.001));
        assertEquals("1234567.0", Json.decimal(1234567));
        assertEquals("null", Json.decimal(Double.NaN));
        assertEquals("null", Json.decimal(Double.POSITIVE_INFINITY));
    }

    @Test
    void stringsAreEscaped() {
        String json = new Json().beginObject().field("n", "a\"b\\c\nd\u0001e é").endObject().toString();
        assertEquals("{\"n\":\"a\\\"b\\\\c\\nd\\u0001e\\u2028é\"}", json);
    }

    @Test
    void commasAndNullsArePlacedCorrectly() {
        String json = new Json().beginObject()
                .field("a", 1)
                .field("b", (String) null)
                .name("c").beginArray().value(1L).value(true).beginObject().endObject().endArray()
                .name("d").beginObject().decimalField("x", 1.5).decimalField("y", null).endObject()
                .endObject().toString();
        assertEquals("{\"a\":1,\"b\":null,\"c\":[1,true,{}],\"d\":{\"x\":1.5,\"y\":null}}", json);
    }
}
