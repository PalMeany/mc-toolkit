#!/usr/bin/env python3
"""validate.py SERVERDIR LABEL [--selftest] [--stop console|signal] [--watch SECONDS]

Live test of the bridge on a server started by start_server.sh: types forceload/summon/time
commands on its console, watches .toolkit/, validates every new file version against
companion/PROTOCOL.md (keys, types, rounding, null-not-omitted), checks the 5 s cadence, sane
TPS/MSPT and that chunk/entity counts and day time follow the commands. With --selftest (and
the self-test jar) it also checks the detached-player timeline: detail file written, not
rewritten while unchanged, rewritten on change, deleted when the player leaves. Finally it stops
the server by its PID (typing "stop" on the console with --stop console, else SIGTERM; SIGTERM
is the fallback either way) and checks the shutdown write (running=false, no players, no player
files). One sample of every file is saved to SERVERDIR/bridge-samples/. Exit code 0 = passed.
"""
import hashlib, json, os, re, signal, sys, time, uuid

d, label = sys.argv[1], sys.argv[2]
selftest = "--selftest" in sys.argv
watch = int(sys.argv[sys.argv.index("--watch") + 1]) if "--watch" in sys.argv else (95 if selftest else 40)
tk = os.path.join(d, ".toolkit")
samples_dir = os.path.join(d, "bridge-samples")
os.makedirs(samples_dir, exist_ok=True)
pid = int(open(os.path.join(d, "server.pid")).read().strip())
stop_mode = sys.argv[sys.argv.index("--stop") + 1] if "--stop" in sys.argv else "signal"
console = open(os.path.join(d, "console.fifo"), "w")  # unblocks the server's stdin; held open until the end

UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
META = {"protocol", "version", "platform", "software", "minecraft", "updated_at", "interval", "running"}
STATE = {"name", "mode", "world", "dimension", "x", "y", "z", "health", "max_health", "food", "saturation",
         "level", "exp", "ping", "op", "joined_at"}
PIN = {"uuid", "name", "world", "dimension", "x", "y", "z"}
WORLD = {"name", "dimension", "players", "chunks", "entity_count", "entities"}
DETAIL = {"bridge", "uuid", "name", "inventory", "armor", "offhand", "ender_chest", "effects"}
ITEM = {"id", "count", "name", "enchanted", "damage", "max_damage"}

h = bytearray(hashlib.md5(b"mctoolkit-bridge-selftest").digest())
h[6] = (h[6] & 0x0F) | 0x30
h[8] = (h[8] & 0x3F) | 0x80
FAKE = str(uuid.UUID(bytes=bytes(h)))

errors = []


def check(cond, msg):
    if not cond:
        errors.append(msg)
        print("  FAIL:", msg, flush=True)
    return cond


def alive():
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def read(name):
    path = os.path.join(tk, name)
    try:
        raw = open(path, "rb").read()
    except FileNotFoundError:
        return None
    check(not raw.startswith(b"\xef\xbb\xbf"), f"{name}: BOM")
    try:
        return json.loads(raw.decode("utf-8"))
    except Exception as e:  # a half-written file would land here
        check(False, f"{name}: not valid JSON ({e}): {raw[:80]!r}")
        return None


def num(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def dec2(v):
    return v is None or (num(v) and abs(round(v, 2) - v) < 1e-9)


def check_meta(name, doc, running):
    m = doc.get("bridge")
    if not check(isinstance(m, dict) and set(m) == META, f"{name}: meta keys {m and sorted(m)}"):
        return
    check(m["protocol"] == 1 and re.match(r"^\d+\.\d+\.\d+", str(m["version"])) and m["interval"] == 5, f"{name}: meta values {m}")
    check(m["platform"] in {"fabric", "quilt", "neoforge", "forge"}, f"{name}: platform {m['platform']}")
    check(isinstance(m["software"], str) and m["software"], f"{name}: software {m['software']!r}")
    check(isinstance(m["minecraft"], str) and m["minecraft"], f"{name}: minecraft {m['minecraft']!r}")
    check(m["running"] is running, f"{name}: running {m['running']} (expected {running})")
    check(isinstance(m["updated_at"], int) and abs(time.time() - m["updated_at"]) < 30, f"{name}: updated_at {m['updated_at']}")


def check_item(where, it, slot_range=None):
    keys = ITEM | ({"slot"} if slot_range else set())
    if not check(isinstance(it, dict) and set(it) == keys, f"{where}: item keys {it and sorted(it)}"):
        return
    if slot_range:
        check(isinstance(it["slot"], int) and it["slot"] in slot_range, f"{where}: slot {it['slot']}")
    check(isinstance(it["id"], str) and re.match(r"^[a-z0-9_.-]+:[a-z0-9_./-]+$", it["id"]), f"{where}: id {it['id']}")
    check(isinstance(it["count"], int) and it["count"] > 0, f"{where}: count {it['count']}")
    check(it["name"] is None or isinstance(it["name"], str), f"{where}: name")
    check(isinstance(it["enchanted"], bool), f"{where}: enchanted")
    check((it["damage"] is None) == (it["max_damage"] is None), f"{where}: damage/max_damage pairing")


def check_state(doc, running=True):
    check_meta("state.json", doc, running)
    players = doc.get("players")
    if not check(isinstance(players, dict), "state.json: players not an object"):
        return
    for u, p in players.items():
        check(UUID_RE.match(u), f"state.json: key {u}")
        if not check(set(p) == STATE, f"state.json: player keys {sorted(p)}"):
            continue
        check(p["mode"] in {"survival", "creative", "adventure", "spectator", None}, f"state: mode {p['mode']}")
        for k in ("x", "y", "z", "health", "max_health", "saturation", "exp"):
            check(p[k] is None or (num(p[k]) and dec2(p[k])), f"state: {k}={p[k]}")
        for k in ("food", "level", "ping", "joined_at"):
            check(p[k] is None or isinstance(p[k], int), f"state: {k}={p[k]}")
        check(p["op"] is None or isinstance(p["op"], bool), f"state: op")
        check(p["exp"] is None or 0 <= p["exp"] <= 1, f"state: exp range")


def check_map(doc, running=True):
    check_meta("map.json", doc, running)
    check(set(doc) == {"bridge", "time", "players"}, f"map.json keys {sorted(doc)}")
    check(doc.get("time") is None or (isinstance(doc["time"], int) and 0 <= doc["time"] <= 23999), f"map: time {doc.get('time')}")
    for pin in doc.get("players", []):
        check(set(pin) == PIN, f"map: pin keys {sorted(pin)}")
        check(all(dec2(pin[k]) for k in "xyz"), "map: coords not 2-decimal")


def check_perf(doc, running=True):
    check_meta("performance.json", doc, running)
    check(set(doc) == {"bridge", "tps", "mspt", "tps_avg", "worlds"}, f"performance keys {sorted(doc)}")
    check(num(doc.get("tps")) and 0 <= doc["tps"] <= 20 and dec2(doc["tps"]), f"perf: tps {doc.get('tps')}")
    check(doc.get("mspt") is None or (num(doc["mspt"]) and doc["mspt"] >= 0 and dec2(doc["mspt"])), f"perf: mspt {doc.get('mspt')}")
    avg = doc.get("tps_avg", {})
    check(set(avg) == {"one", "five", "fifteen"} and all(num(v) and 0 <= v <= 20 for v in avg.values()), f"perf: tps_avg {avg}")
    for w in doc.get("worlds", []):
        if not check(set(w) == WORLD, f"perf: world keys {sorted(w)}"):
            continue
        check(isinstance(w["players"], int) and isinstance(w["chunks"], int) and isinstance(w["entity_count"], int), f"perf: world ints {w}")
        ents = w["entities"]
        check(isinstance(ents, dict) and len(ents) <= 10, f"perf: entities {ents}")
        counts = list(ents.values())
        check(counts == sorted(counts, reverse=True) and sum(counts) <= w["entity_count"], f"perf: entity order/sum {w}")


def check_detail(doc, running=True):
    check_meta("players/*.json", doc, running)
    if not check(set(doc) == DETAIL, f"detail keys {sorted(doc)}"):
        return
    for it in doc["inventory"] or []:
        check_item("inventory", it, range(0, 36))
    for piece in ("head", "chest", "legs", "feet"):
        if doc["armor"][piece] is not None:
            check_item("armor." + piece, doc["armor"][piece])
    if doc["offhand"] is not None:
        check_item("offhand", doc["offhand"])
    for it in doc["ender_chest"] or []:
        check_item("ender_chest", it, range(0, 27))
    for e in doc["effects"] or []:
        check(set(e) == {"id", "amplifier", "duration"} and isinstance(e["duration"], int), f"effect {e}")


def bridge_log_lines():
    try:
        lines = open(os.path.join(d, "server.log"), encoding="utf-8", errors="replace").read().splitlines()
    except FileNotFoundError:
        return [], []
    ours = [l for l in lines if "mctoolkit" in l.lower() or "MCToolkit" in l]
    problems = [l for l in lines if re.search(r"(ERROR|Exception|Error:)", l) and ("mctoolkit" in l.lower() or "palmeany" in l)]
    return ours, problems


# ---------------------------------------------------------------- startup
print(f"[{label}] pid {pid}, waiting for .toolkit files", flush=True)
t0 = time.time()
while time.time() - t0 < 300:
    if not alive():
        check(False, "server process died during startup")
        break
    if all(os.path.exists(os.path.join(tk, n)) for n in ("performance.json", "state.json", "map.json")):
        break
    time.sleep(0.5)
else:
    check(False, "files did not appear within 300 s")
print(f"[{label}] files present after {time.time() - t0:.0f}s", flush=True)
ours, _ = bridge_log_lines()
for l in ours:
    print("  LOG", l[:400])

# known world content: 3x3 force-loaded chunks, 3 cows + 2 armor stands, noon
for cmd in ["forceload add 0 0 47 47", "summon minecraft:cow 8 -60 8", "summon minecraft:cow 9 -60 8",
            "summon minecraft:cow 10 -60 8", "summon minecraft:armor_stand 16 -60 16", "summon minecraft:armor_stand 17 -60 16",
            "time set 6000"]:
    console.write(cmd + "\n")
console.flush()
cmd_time = time.time()

# ---------------------------------------------------------------- observe cycles
seen = {}          # name -> list of (updated_at, wall time)
timeline = []      # selftest: (updated_at, in_state, detail_exists, mtime, torch_count)
samples = {}
perf_values = []
world_checks = []
time_checks = []
end = time.time() + watch
while time.time() < end and alive():
    st = read("state.json")
    if st and st.get("bridge", {}).get("updated_at") != (seen.get("state.json") or [(None,)])[-1][0]:
        ua = st["bridge"]["updated_at"]
        seen.setdefault("state.json", []).append((ua, time.time()))
        time.sleep(1.0)  # let the rest of this cycle's files land
        st = read("state.json") or st
        check_state(st)
        mp = read("map.json")
        if mp:
            check_map(mp)
            seen.setdefault("map.json", []).append((mp["bridge"]["updated_at"], time.time()))
        pf = read("performance.json")
        if pf:
            check_perf(pf)
            seen.setdefault("performance.json", []).append((pf["bridge"]["updated_at"], time.time()))
            perf_values.append((pf["tps"], pf["mspt"], pf["tps_avg"], [(w["name"], w["chunks"], w["entity_count"]) for w in pf["worlds"]]))
            if time.time() - cmd_time > 6:
                ow = next((w for w in pf["worlds"] if w["dimension"] == "overworld"), None)
                ents = (ow or {}).get("entities") or {}
                world_checks.append(bool(ow and ow["chunks"] >= 9 and ow["entity_count"] >= 5
                                         and ents.get("minecraft:armor_stand") == 2 and ents.get("minecraft:cow", 0) >= 3))
                if mp:
                    time_checks.append(mp["time"])
            samples["performance.json"] = pf
        for f in os.listdir(os.path.join(tk, "players")) if os.path.isdir(os.path.join(tk, "players")) else []:
            if f.endswith(".json"):
                dt = read("players/" + f)
                if dt:
                    check_detail(dt)
        if selftest:
            path = os.path.join(tk, "players", FAKE + ".json")
            exists = os.path.exists(path)
            torch = None
            mtime = None
            if exists:
                mtime = os.stat(path).st_mtime_ns
                dt = read("players/" + FAKE + ".json")
                torch = next((i["count"] for i in dt["inventory"] if i["id"] == "minecraft:torch"), None) if dt else None
                if torch == 32 and "players/<uuid>.json" not in samples:
                    samples["players/<uuid>.json"] = dt
            in_state = FAKE in st["players"]
            if in_state and "state.json" not in samples:
                samples["state.json"] = st
                samples["map.json"] = mp
                samples["performance.json (selftest present)"] = pf
            timeline.append((ua, in_state, exists, mtime, torch))
        else:
            samples.setdefault("state.json", st)
            samples.setdefault("map.json", mp)
    time.sleep(0.25)

check(alive(), "server died while being observed")

# cadence
for name, events in seen.items():
    uas = [e[0] for e in events]
    diffs = [b - a for a, b in zip(uas, uas[1:])]
    print(f"[{label}] {name}: {len(uas)} writes, updated_at deltas {diffs}", flush=True)
    check(len(uas) >= 3, f"{name}: fewer than 3 refreshes seen")
    check(all(4 <= x <= 6 for x in diffs), f"{name}: refresh interval not ~5 s: {diffs}")

if perf_values:
    tps, mspt, avg, worlds = perf_values[-1]
    print(f"[{label}] last perf: tps={tps} mspt={mspt} avg={avg} worlds={worlds}", flush=True)
    series = [v[0] for v in perf_values]
    print(f"[{label}] tps series: {series}", flush=True)
    print(f"[{label}] mspt series: {[v[1] for v in perf_values]}", flush=True)
    check(min(series[2:] or [20]) >= 15, f"tps implausibly low after warm-up: {series}")
    check(all(v[1] is not None and 0 < v[1] < 50 for v in perf_values), f"mspt implausible")
    check(any(w[1] and w[1] > 0 for w in worlds), "no loaded chunks reported")
    check(world_checks and all(world_checks[1:]), f"forceloaded chunks / summoned entities not reflected: {world_checks}")
    print(f"[{label}] day time after 'time set 6000': {time_checks}", flush=True)
    check(time_checks and all(6000 <= t <= 8000 for t in time_checks[1:]), f"day time not following 'time set 6000': {time_checks}")
paused = [l for l in open(os.path.join(d, "server.log"), errors="replace") if "pausing" in l]
print(f"[{label}] server paused while empty: {bool(paused)}", flush=True)

# selftest timeline
if selftest:
    print(f"[{label}] selftest timeline (updated_at, in_state, file, mtime, torches):")
    for row in timeline:
        print("   ", row)
    present = [r for r in timeline if r[1]]
    check(len(present) >= 4, "fake player present in fewer than 4 cycles")
    first = [r for r in present if r[4] == 32]
    second = [r for r in present if r[4] == 7]
    check(len(first) >= 2 and len({r[3] for r in first}) == 1, f"detail file rewritten without a change: {first}")
    check(len(second) >= 1 and second[0][3] not in {r[3] for r in first}, "detail file not rewritten after the change")
    check(len(second) < 2 or len({r[3] for r in second}) == 1, "detail file rewritten again without a change")
    after = timeline[timeline.index(present[-1]) + 1:] if present else []
    check(len(after) >= 1 and all(not r[1] and not r[2] for r in after), f"fake player file not deleted after removal: {after}")
    # content
    st = samples.get("state.json", {})
    p = st.get("players", {}).get(FAKE)
    if check(p is not None, "fake player missing from state sample"):
        exp = {"name": "BridgeSelfTest", "mode": "survival", "dimension": "overworld", "x": 12.5, "y": 64.0, "z": -7.25,
               "health": 17.5, "max_health": 20.0, "food": 20, "level": 0, "ping": None, "op": False}
        for k, v in exp.items():
            check(p[k] == v, f"selftest state.{k} = {p[k]!r}, expected {v!r}")
        check(isinstance(p["saturation"], (int, float)) and isinstance(p["joined_at"], int) and p["exp"] == 0, f"selftest state misc {p}")
    dt = samples.get("players/<uuid>.json")
    if check(dt is not None, "no detail sample of the fake player"):
        inv = {i["slot"]: i for i in dt["inventory"]}
        sw = inv.get(0, {})
        check(sw.get("id") == "minecraft:diamond_sword" and sw.get("name") == "Excalibur" and sw.get("enchanted") is True
              and sw.get("damage") == 25 and sw.get("max_damage") == 1561, f"selftest sword {sw}")
        check(inv.get(1, {}).get("id") == "minecraft:torch" and inv[1]["damage"] is None and inv[1]["name"] is None, f"selftest torch {inv.get(1)}")
        check(inv.get(9, {}).get("id") == "minecraft:diamond" and inv[9]["count"] == 5, f"selftest diamond {inv.get(9)}")
        hd = dt["armor"]["head"] or {}
        check(hd.get("id") == "minecraft:iron_helmet" and hd.get("damage") == 10 and hd.get("max_damage") == 165, f"selftest helmet {hd}")
        check(dt["armor"]["chest"] is None and dt["armor"]["legs"] is None and dt["armor"]["feet"] is None, "selftest other armor")
        check((dt["offhand"] or {}).get("id") == "minecraft:shield", f"selftest offhand {dt['offhand']}")
        check(dt["ender_chest"] == [{"slot": 0, "id": "minecraft:diamond", "count": 12, "name": None, "enchanted": False,
                                     "damage": None, "max_damage": None}], f"selftest ender {dt['ender_chest']}")
        check(dt["effects"] == [{"id": "minecraft:speed", "amplifier": 1, "duration": 600}], f"selftest effects {dt['effects']}")

# ---------------------------------------------------------------- stop by PID and check the shutdown write
t1 = time.time()
if stop_mode == "console":
    print(f"[{label}] typing 'stop' on the console of pid {pid}", flush=True)
    console.write("stop\n")
    console.flush()
    while alive() and time.time() - t1 < 120:
        time.sleep(0.5)
    check(not alive(), "server did not exit within 120 s of 'stop'")
if alive():
    print(f"[{label}] stopping pid {pid} (SIGTERM)", flush=True)
    os.kill(pid, signal.SIGTERM)
    while alive() and time.time() - t1 < 240:
        time.sleep(0.5)
    check(not alive(), "server did not exit after SIGTERM")
print(f"[{label}] exited after {time.time() - t1:.1f}s", flush=True)
st, mp, pf = read("state.json"), read("map.json"), read("performance.json")
if check(st is not None and mp is not None and pf is not None, "final files missing"):
    check_state(st, running=False)
    check_map(mp, running=False)
    check_perf(pf, running=False)
    check(st["players"] == {} and mp["players"] == [], "final write still lists players")
    samples["final state.json"] = st
    samples["final map.json"] = mp
left = [f for f in os.listdir(os.path.join(tk, "players"))] if os.path.isdir(os.path.join(tk, "players")) else []
check(left == [], f"player files left after shutdown: {left}")
check(not any(f.endswith(".tmp") for f in os.listdir(tk)), "temp files left behind")

ours, problems = bridge_log_lines()
final_lines = [l for l in ours if "final state" in l]
if stop_mode == "console":
    check(final_lines, "no 'final state' log line")  # on SIGTERM log4j may already be shut down
for l in final_lines:
    print("  LOG", l[:300])
for l in problems:
    print("  PROBLEM", l[:400])
check(not problems, "error lines mention the bridge")

for name, doc in samples.items():
    with open(os.path.join(samples_dir, name.replace("/", "_").replace(" ", "_").replace("<uuid>", "selftest")), "w") as fh:
        json.dump(doc, fh, separators=(",", ":"))
print(f"[{label}] {'PASS' if not errors else 'FAIL (' + str(len(errors)) + ')'}", flush=True)
sys.exit(1 if errors else 0)
