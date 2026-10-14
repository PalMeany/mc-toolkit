package io.github.palmeany.mctoolkit.bridge;

/** Small text helpers shared by the readers. */
final class Text {

    private Text() {
    }

    /** {@code text} without legacy formatting codes ({@code §} plus the following character), null stays null. */
    static String stripLegacy(String text) {
        if (text == null || text.indexOf('§') < 0) {
            return text;
        }
        StringBuilder out = new StringBuilder(text.length());
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            if (c == '§') {
                i++; // skip the code character too
            } else {
                out.append(c);
            }
        }
        return out.toString();
    }
}
