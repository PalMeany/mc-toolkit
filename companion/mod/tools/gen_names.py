#!/usr/bin/env python3
"""Generate the bridge's runtime name tables from the official mapping files.

    python3 tools/gen_names.py            (run from companion/mod; needs curl on PATH)

Reads tools/members.txt (Mojang names) and, for every Minecraft release the bridge supports
on an obfuscated runtime, joins three mapping sets on the obfuscated names:

  * Mojang client mappings (piston-meta, ProGuard format)      Mojang name -> obfuscated
  * Fabric intermediary v2 (maven.fabricmc.net, tiny v2)       obfuscated  -> class_/method_/field_
  * MCPConfig joined.tsrg (maven.minecraftforge.net, tsrg2)    obfuscated  -> m_xxxxx_/f_xxxxx_

and writes, under src/{main,selftest}/resources/io/github/palmeany/mctoolkit/bridge/names/:

  intermediary.tsv   Fabric/Quilt on 1.20.1 .. 1.21.11 (classes and members)
  srg.tsv            Forge 1.20.1 .. 1.20.4 and NeoForge 1.20.1 .. 1.20.4 (members; classes keep Mojang names)

Rows:  C <mojang class> <runtime class>
       M <mojang owner> <name> (<mojang param types>) <runtime name>
       F <mojang owner> <name> <runtime name>
A member that had different runtime names across releases gets one row per name; the bridge
tries them in order. Downloads are cached in build/mappings-cache. A per-release availability
report (Mojang names) is written to build/names-report.txt.
"""
from __future__ import annotations

import io
import json
import os
import re
import subprocess
import sys
import zipfile
from collections import OrderedDict, defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CACHE = os.path.join(ROOT, "build", "mappings-cache")
SPEC = os.path.join(ROOT, "tools", "members.txt")
OUT = {
    "main": os.path.join(ROOT, "src", "main", "resources", "io", "github", "palmeany", "mctoolkit", "bridge", "names"),
    "selftest": os.path.join(ROOT, "src", "selftest", "resources", "io", "github", "palmeany", "mctoolkit", "bridge", "names"),
}
REPORT = os.path.join(ROOT, "build", "names-report.txt")

# Obfuscated runtimes. Intermediary: every release Fabric supports from 1.20.1 to the last
# obfuscated release. SRG: Forge/NeoForge switched to Mojang names at runtime with 1.20.5/1.20.6.
INTERMEDIARY_RANGE = ("1.20.1", "1.21.11")
SRG_VERSIONS = ["1.20.1", "1.20.2", "1.20.3", "1.20.4"]

MANIFEST = "https://piston-meta.mojang.com/mc/game/version_manifest_v2.json"
INTERMEDIARY = "https://maven.fabricmc.net/net/fabricmc/intermediary/{v}/intermediary-{v}-v2.jar"
MCP_META = "https://maven.minecraftforge.net/de/oceanlabs/mcp/mcp_config/maven-metadata.xml"
MCP_ZIP = "https://maven.minecraftforge.net/de/oceanlabs/mcp/mcp_config/{v}/mcp_config-{v}.zip"

PRIMS = {"void": "V", "boolean": "Z", "byte": "B", "char": "C", "short": "S", "int": "I",
         "long": "J", "float": "F", "double": "D"}


# ----------------------------------------------------------------------------- downloads

def fetch(url: str, name: str) -> str:
    """Download url into the cache (once) and return the local path."""
    os.makedirs(CACHE, exist_ok=True)
    path = os.path.join(CACHE, name)
    if not os.path.exists(path):
        print("  fetch", url, file=sys.stderr)
        tmp = path + ".part"
        subprocess.run(["curl", "-fsSL", "--retry", "3", "-o", tmp, url], check=True)
        os.replace(tmp, path)
    return path


def vkey(v: str) -> tuple:
    return tuple(int(p) for p in v.split("."))


def releases(manifest: dict, lo: str, hi: str) -> list[str]:
    out = [v["id"] for v in manifest["versions"]
           if v["type"] == "release" and re.fullmatch(r"\d+\.\d+(\.\d+)?", v["id"])
           and vkey(lo) <= vkey(v["id"]) <= vkey(hi)]
    return sorted(out, key=vkey)


# ----------------------------------------------------------------------------- parsing

class Mojang:
    """ProGuard mapping: Mojang -> obfuscated, with member signatures in Mojang names."""

    def __init__(self, path: str):
        self.classes: dict[str, str] = {}
        self.methods: dict[str, list] = defaultdict(list)  # owner -> [(name, params, ret, obf)]
        self.fields: dict[str, list] = defaultdict(list)   # owner -> [(name, type, obf)]
        current = None
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("#") or not line.strip():
                    continue
                if not line.startswith(" "):
                    moj, obf = line.rstrip().rstrip(":").split(" -> ")
                    self.classes[moj] = obf
                    current = moj
                    continue
                body, obf = line.strip().split(" -> ")
                body = re.sub(r"^\d+:\d+:", "", body)
                if "(" in body:
                    ret, rest = body.split(" ", 1)
                    name, params = rest.split("(", 1)
                    params = params.rstrip(")")
                    params = [p for p in params.split(",") if p] if params else []
                    self.methods[current].append((name, params, ret, obf))
                else:
                    ftype, name = body.split(" ", 1)
                    self.fields[current].append((name, ftype, obf))

    def obf_class(self, moj: str) -> str:
        return self.classes.get(moj, moj)

    def declarers(self, name: str, params: list[str], ret: str, obf: str) -> list[str]:
        """Classes declaring a method with this Mojang signature and obfuscated name.

        Intermediary lists an overriding method only on the supertype that introduced it, and
        the ProGuard file carries no hierarchy; an override keeps its supertype's name and
        signature, so these classes are the candidate supertypes. A wrong candidate is harmless:
        its runtime name does not exist on the real owner and the bridge skips it."""
        if not hasattr(self, "_by_sig"):
            self._by_sig = defaultdict(list)
            for owner, methods in self.methods.items():
                for mname, mparams, mret, mobf in methods:
                    self._by_sig[(mname, tuple(mparams), mret, mobf)].append(owner)
        return self._by_sig.get((name, tuple(params), ret, obf), [])

    def desc(self, t: str) -> str:
        dims = 0
        while t.endswith("[]"):
            dims += 1
            t = t[:-2]
        base = PRIMS.get(t) or "L" + self.obf_class(t).replace(".", "/") + ";"
        return "[" * dims + base

    def method_desc(self, params: list[str], ret: str) -> str:
        return "(" + "".join(self.desc(p) for p in params) + ")" + self.desc(ret)


class Tiny:
    """Fabric intermediary tiny v2: official -> intermediary."""

    def __init__(self, text: str):
        self.classes: dict[str, str] = {}
        self.methods: dict[tuple, str] = {}
        self.fields: dict[tuple, str] = {}
        lines = text.splitlines()
        header = lines[0].split("\t")
        assert header[0] == "tiny" and header[3] == "official" and header[4] == "intermediary", header
        owner = None
        for line in lines[1:]:
            if line.startswith("c\t"):
                _, obf, inter = line.split("\t")[:3]
                self.classes[obf] = inter
                owner = obf
            elif line.startswith("\tm\t"):
                _, _, desc, obf, inter = line.split("\t")[:5]
                self.methods[(owner, obf, desc)] = inter
            elif line.startswith("\tf\t"):
                _, _, desc, obf, inter = line.split("\t")[:5]
                self.fields[(owner, obf)] = inter


class Tsrg:
    """MCPConfig joined.tsrg (tsrg2): obfuscated -> SRG."""

    def __init__(self, text: str):
        self.methods: dict[tuple, str] = {}
        self.fields: dict[tuple, str] = {}
        owner = None
        for line in text.splitlines():
            if not line or line.startswith("tsrg2") or line.startswith("\t\t"):
                continue
            if not line.startswith("\t"):
                owner = line.split(" ")[0]
                continue
            parts = line.strip().split(" ")
            if len(parts) >= 3 and parts[1].startswith("("):
                self.methods[(owner, parts[0], parts[1])] = parts[2]
            elif len(parts) == 3:
                self.fields[(owner, parts[0])] = parts[1]
            elif len(parts) == 4:
                self.fields[(owner, parts[0])] = parts[2]


# ----------------------------------------------------------------------------- spec

def read_spec() -> dict[str, list]:
    sections: dict[str, list] = OrderedDict()
    current = None
    with open(SPEC, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.split("#", 1)[0].strip()
            if not line:
                continue
            if line.startswith("[") and line.endswith("]"):
                current = line[1:-1]
                sections[current] = []
                continue
            kind, rest = line.split(" ", 1)
            if kind == "C":
                sections[current].append(("C", rest.strip(), None, None))
            elif kind == "F":
                owner, name = rest.split()
                sections[current].append(("F", owner, name, None))
            elif kind == "M":
                owner, sig = rest.split(" ", 1)
                name, params = sig.split("(", 1)
                params = params.rstrip(")")
                plist = [p.strip() for p in params.split(",") if p.strip()] if params else []
                sections[current].append(("M", owner, name, plist))
            else:
                raise SystemExit(f"bad spec line: {raw!r}")
    return sections


def classes_of(entry) -> list[str]:
    kind, owner, _, params = entry
    out = [owner]
    for p in params or []:
        p = p.replace("[]", "")
        if p not in PRIMS and "." in p:
            out.append(p)
    return out


def key_of(entry) -> str:
    kind, owner, name, params = entry
    if kind == "C":
        return f"C {owner}"
    if kind == "F":
        return f"F {owner}#{name}"
    return f"M {owner}#{name}({','.join(params)})"


# ----------------------------------------------------------------------------- join

def lookup(moj: Mojang, entry, member_map: dict, field_map: dict) -> set[str]:
    """Runtime names of one spec member in one release, empty when it does not exist."""
    kind, owner, name, params = entry
    if owner not in moj.classes:
        return set()
    obf_owner = moj.obf_class(owner).replace(".", "/")
    found = set()
    if kind == "F":
        for fname, _ftype, obf in moj.fields.get(owner, []):
            if fname == name:
                found.add(field_map.get((obf_owner, obf)) or (obf if obf == name else None))
    else:
        for mname, mparams, ret, obf in moj.methods.get(owner, []):
            if mname == name and mparams == params:
                if name == "<init>":
                    found.add("<init>")
                    continue
                desc = moj.method_desc(mparams, ret)
                runtime = member_map.get((obf_owner, obf, desc))
                if runtime is None:
                    for sup in moj.declarers(mname, mparams, ret, obf):
                        alt = member_map.get((moj.obf_class(sup).replace(".", "/"), obf, desc))
                        if alt is not None:
                            found.add(alt)
                            runtime = runtime or alt
                if runtime is None and obf == name:
                    runtime = name  # not obfuscated (library interface method, e.g. Component.getString)
                found.add(runtime)
    if None in found:
        raise SystemExit(f"no mapping for {key_of(entry)}")
    return found


def main() -> None:
    sections = read_spec()
    manifest = json.load(open(fetch(MANIFEST, "version_manifest_v2.json"), encoding="utf-8"))
    urls = {v["id"]: v["url"] for v in manifest["versions"]}
    inter_versions = releases(manifest, *INTERMEDIARY_RANGE)
    all_versions = sorted(set(inter_versions) | set(SRG_VERSIONS), key=vkey)

    mojang: dict[str, Mojang] = {}
    for v in all_versions:
        meta = json.load(open(fetch(urls[v], f"version-{v}.json"), encoding="utf-8"))
        mojang[v] = Mojang(fetch(meta["downloads"]["client_mappings"]["url"], f"client-{v}.txt"))

    tiny: dict[str, Tiny] = {}
    for v in inter_versions:
        with zipfile.ZipFile(fetch(INTERMEDIARY.format(v=v), f"intermediary-{v}-v2.jar")) as z:
            tiny[v] = Tiny(z.read("mappings/mappings.tiny").decode("utf-8"))

    mcp_meta = open(fetch(MCP_META, "mcp_config-maven-metadata.xml"), encoding="utf-8").read()
    srg: dict[str, Tsrg] = {}
    for v in SRG_VERSIONS:
        builds = sorted(re.findall(rf"<version>({re.escape(v)}-\d{{8}}\.\d{{6}})</version>", mcp_meta))
        mcp_version = builds[-1] if builds else v
        with zipfile.ZipFile(fetch(MCP_ZIP.format(v=mcp_version), f"mcp_config-{mcp_version}.zip")) as z:
            srg[v] = Tsrg(z.read("config/joined.tsrg").decode("utf-8"))

    report_lines = []
    for section, entries in sections.items():
        tables = {"intermediary": OrderedDict(), "srg": OrderedDict()}
        class_names: set[str] = set()
        presence: dict[str, list[str]] = OrderedDict()

        for entry in entries:
            class_names.update(classes_of(entry))
            if entry[0] == "C":
                continue
            key = key_of(entry)
            presence[key] = [v for v in all_versions if _exists(mojang[v], entry)]
            for ns, versions, maps in (("intermediary", inter_versions, tiny), ("srg", SRG_VERSIONS, srg)):
                names: list[str] = []
                for v in versions:
                    m = maps[v]
                    for n in sorted(lookup(mojang[v], entry, m.methods, m.fields)):
                        if n not in names:
                            names.append(n)
                if names:
                    tables[ns][key] = (entry, names)

        # classes: only intermediary renames them
        class_rows = OrderedDict()
        for cls in sorted(class_names):
            names = []
            for v in inter_versions:
                obf = mojang[v].classes.get(cls)
                if obf is None:
                    continue
                inter = tiny[v].classes.get(obf.replace(".", "/"))
                if inter is None:
                    continue
                inter = inter.replace("/", ".")
                if inter not in names:
                    names.append(inter)
            if names:
                class_rows[cls] = names

        os.makedirs(OUT[section], exist_ok=True)
        suffix = "" if section == "main" else "-selftest"
        for ns in ("intermediary", "srg"):
            versions = inter_versions if ns == "intermediary" else SRG_VERSIONS
            path = os.path.join(OUT[section], f"{ns}{suffix}.tsv")
            with open(path, "w", encoding="utf-8", newline="\n") as out:
                out.write(f"# MC Toolkit bridge name table, namespace {ns} ({section})\n")
                out.write(f"# generated by tools/gen_names.py from Mojang mappings + "
                          f"{'Fabric intermediary' if ns == 'intermediary' else 'MCPConfig'} for "
                          f"{versions[0]} .. {versions[-1]} ({len(versions)} releases); do not edit\n")
                if ns == "intermediary":
                    for cls, names in class_rows.items():
                        for n in names:
                            if n != cls:
                                out.write(f"C\t{cls}\t{n}\n")
                for key, (entry, names) in tables[ns].items():
                    kind, owner, name, params = entry
                    for n in names:
                        if kind == "F":
                            out.write(f"F\t{owner}\t{name}\t{n}\n")
                        elif name != "<init>":
                            out.write(f"M\t{owner}\t{name}\t({','.join(params)})\t{n}\n")
            print(f"wrote {os.path.relpath(path, ROOT)}", file=sys.stderr)

        report_lines.append(f"[{section}] availability by release (Mojang names; 26.x not covered: unobfuscated)")
        for key, versions in presence.items():
            report_lines.append(f"  {key:<110} {ranges(versions, all_versions)}")

    os.makedirs(os.path.dirname(REPORT), exist_ok=True)
    with open(REPORT, "w", encoding="utf-8") as fh:
        fh.write("\n".join(report_lines) + "\n")
    print("\n".join(report_lines))


def _exists(moj: Mojang, entry) -> bool:
    kind, owner, name, params = entry
    if kind == "F":
        return any(f[0] == name for f in moj.fields.get(owner, []))
    return any(m[0] == name and m[1] == params for m in moj.methods.get(owner, []))


def ranges(present: list[str], universe: list[str]) -> str:
    if not present:
        return "-- none --"
    if present == universe:
        return "all"
    out, run = [], []
    for v in universe:
        if v in present:
            run.append(v)
        elif run:
            out.append(run[0] if len(run) == 1 else f"{run[0]}..{run[-1]}")
            run = []
    if run:
        out.append(run[0] if len(run) == 1 else f"{run[0]}..{run[-1]}")
    return ", ".join(out)


if __name__ == "__main__":
    main()
