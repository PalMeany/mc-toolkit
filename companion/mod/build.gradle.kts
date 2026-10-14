plugins {
    java
}

group = "io.github.palmeany.mctoolkit"
// One version for every bridge build: companion/VERSION (shared with the Bukkit bridge, read-only here).
version = rootDir.resolve("../VERSION").takeIf { it.isFile }?.readText()?.trim()?.ifEmpty { null } ?: "1.0.0"

repositories {
    mavenCentral()
}

java {
    toolchain.languageVersion = JavaLanguageVersion.of(17)
}

// No loader or Minecraft jar is on the compile classpath: Minecraft is reached by reflection
// through the generated name tables, and the few loader types the jar must name (Fabric's
// server entrypoint interface, the Forge/NeoForge @Mod annotations) come from tiny stub
// sources that are compiled against but never packaged.
val stubs: SourceSet = sourceSets.create("stubs")

sourceSets.main {
    compileClasspath += stubs.output
}

// Development-only: a variant jar that also pushes a detached ServerPlayer through the bridge so
// the player path can be verified on a live server without a client (README, "Testing").
val selftest: SourceSet = sourceSets.create("selftest") {
    compileClasspath += sourceSets.main.get().output + stubs.output + configurations["compileClasspath"]
}

dependencies {
    // Provided by every supported server (Minecraft itself logs through SLF4J since 1.17).
    compileOnly("org.slf4j:slf4j-api:2.0.9")

    testImplementation(platform("org.junit:junit-bom:5.13.4"))
    testImplementation("org.junit.jupiter:junit-jupiter")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

tasks.withType<JavaCompile>().configureEach {
    options.encoding = "UTF-8"
    options.release = 17
    options.compilerArgs.addAll(listOf("-Xlint:all", "-Xlint:-processing", "-Xlint:-serial"))
}

tasks.test {
    useJUnitPlatform()
}

val metadataFiles = listOf("fabric.mod.json", "META-INF/mods.toml", "META-INF/neoforge.mods.toml", "**/bridge.properties")

tasks.processResources {
    val props = mapOf("version" to project.version)
    inputs.properties(props)
    filteringCharset = "UTF-8"
    filesMatching(metadataFiles) { expand(props) }
}

fun Jar.bridgeManifest() {
    manifest {
        attributes(
            "Implementation-Title" to "MC Toolkit Bridge",
            "Implementation-Version" to project.version,
            // Stable JPMS name on Forge/NeoForge (otherwise derived from the file name).
            "Automatic-Module-Name" to "mctoolkit_bridge",
        )
    }
}

tasks.jar {
    archiveFileName = "mctoolkit-bridge.jar"
    bridgeManifest()
}

tasks.register<Jar>("selftestJar") {
    group = "build"
    description = "Builds the development-only self-test variant of the bridge jar."
    archiveFileName = "mctoolkit-bridge-selftest.jar"
    destinationDirectory = layout.buildDirectory.dir("selftest")
    from(sourceSets.main.get().output)
    from(selftest.output)
    bridgeManifest()
}

// Copies the release jar into the panel plugin's bundle folder (resources/bridge/).
tasks.register<Copy>("bundle") {
    group = "build"
    description = "Copies mctoolkit-bridge.jar into ../../resources/bridge/."
    from(tasks.jar)
    into(rootDir.resolve("../../resources/bridge"))
}
