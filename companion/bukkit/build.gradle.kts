plugins {
    java
}

group = "io.github.palmeany.mctoolkit"
// One version for every bridge build: companion/VERSION (shared with the mod bridge, read-only here).
version = rootDir.resolve("../VERSION").takeIf { it.isFile }?.readText()?.trim()?.ifEmpty { null } ?: "1.0.0"

repositories {
    mavenCentral()
    maven("https://hub.spigotmc.org/nexus/content/repositories/snapshots/")
    maven("https://oss.sonatype.org/content/repositories/snapshots/")
}

// The oldest supported API: anything that compiles against it exists on every Bukkit-family server
// from 1.8.8 to 26.x. Everything newer (NamespacedKey, attributes, offhand, ping, Paper/Folia API)
// is reached through reflection in the sources.
val bukkitApi = "org.spigotmc:spigot-api:1.8.8-R0.1-SNAPSHOT"
// spigot-api 1.8.8 points at bungeecord-chat 1.8-SNAPSHOT, which no repository serves any more; the released
// artifact has the same classes (only needed so that Player's signatures resolve). The other libraries the old
// API needs at test runtime are listed by hand for the same reason.
val bungeeChat = "net.md-5:bungeecord-chat:1.16-R0.4"

dependencies {
    compileOnly(bukkitApi) { isTransitive = false }
    compileOnly(bungeeChat) { isTransitive = false }

    testImplementation(bukkitApi) { isTransitive = false }
    testImplementation(bungeeChat) { isTransitive = false }
    testRuntimeOnly("commons-lang:commons-lang:2.6")
    testRuntimeOnly("com.google.guava:guava:17.0")
    testRuntimeOnly("com.google.code.gson:gson:2.2.4")
    testRuntimeOnly("org.yaml:snakeyaml:1.15")
    testImplementation(platform("org.junit:junit-bom:5.13.4"))
    testImplementation("org.junit.jupiter:junit-jupiter")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

java {
    toolchain.languageVersion = JavaLanguageVersion.of(25)
}

tasks.withType<JavaCompile>().configureEach {
    options.encoding = "UTF-8"
    // Bytecode 52: the jar must load on Java 8 servers (1.8.8 – 1.16.5).
    options.release = 8
    // javac 25 warns that release 8 is obsolete; that is the point here.
    options.compilerArgs.addAll(listOf("-Xlint:deprecation", "-Xlint:-options"))
}

tasks.processResources {
    val props = mapOf("version" to project.version)
    inputs.properties(props)
    filteringCharset = "UTF-8"
    filesMatching("plugin.yml") { expand(props) }
}

tasks.jar {
    archiveFileName = "MCToolkitBridge.jar"
    manifest {
        attributes(
            "Implementation-Title" to "MCToolkitBridge",
            "Implementation-Version" to project.version,
            // No server internals are referenced, so Paper 1.20.5+ can skip its plugin remapping pass.
            "paperweight-mappings-namespace" to "mojang",
        )
    }
}

tasks.test {
    useJUnitPlatform()
}

// A second, development-only plugin that pushes fake players through the bridge on a live server
// without a client (see README, "Testing"). Never shipped.
val selftest: SourceSet by sourceSets.creating {
    compileClasspath += sourceSets["main"].output + configurations["compileClasspath"]
}

// The unit tests reuse the self-test's strict JSON parser.
sourceSets.test {
    compileClasspath += selftest.output
    runtimeClasspath += selftest.output
}

tasks.named<ProcessResources>(selftest.processResourcesTaskName) {
    val props = mapOf("version" to project.version)
    inputs.properties(props)
    filteringCharset = "UTF-8"
    filesMatching("plugin.yml") { expand(props) }
}

tasks.register<Jar>("selftestJar") {
    group = "build"
    description = "Builds the development-only self-test plugin."
    archiveFileName = "MCToolkitBridgeSelfTest.jar"
    from(selftest.output)
}
