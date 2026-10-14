#!/usr/bin/env python3
"""fetch_server.py TYPE VERSION DEST: install a ready-made server package from the MCJars API.

TYPE is FABRIC, QUILT, NEOFORGE or FORGE. Follows the build's installation steps
(download / unzip / remove) into DEST; curl does the HTTP.
"""
import json, os, shutil, subprocess, sys, zipfile
t, v, dest = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(dest, exist_ok=True)
def curl(url, out=None):
    args = ["curl", "-fsSL", "--retry", "3", "-A", "mctoolkit-test", url]
    if out: args += ["-o", out]
    return subprocess.run(args, check=True, capture_output=out is None).stdout
build = json.loads(curl(f"https://mcjars.app/api/v3/builds/types/{t}/versions/{v}/latest"))["build"]
print("build", build["name"], flush=True)
for group in build["installation"]:
    for step in group:
        kind = step["type"]
        if kind == "download":
            target = os.path.join(dest, step["file"])
            curl(step["url"], target)
            print("downloaded", step["file"], os.path.getsize(target), flush=True)
        elif kind == "unzip":
            root = os.path.realpath(dest)
            with zipfile.ZipFile(os.path.join(dest, step["file"])) as z:
                for m in z.namelist():
                    if not os.path.realpath(os.path.join(dest, step["location"], m)).startswith(root):
                        raise SystemExit("zip slip: " + m)
                z.extractall(os.path.join(dest, step["location"]))
            print("unzipped", step["file"], flush=True)
        elif kind == "remove":
            p = os.path.join(dest, step["location"])
            if os.path.isdir(p): shutil.rmtree(p)
            elif os.path.exists(p): os.remove(p)
print("done", flush=True)
