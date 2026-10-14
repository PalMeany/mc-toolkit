#!/bin/zsh
# start_server.sh DIR PORT JAVA MODJAR SELFTEST(true|false) -- <launch args...>
# Prepares a headless flat test server on 127.0.0.1:PORT (fresh world, view distance 4, offline
# mode, EULA accepted for local testing), installs MODJAR as mods/mctoolkit-bridge.jar and starts it
# in the background with stdin on DIR/console.fifo (validate.py opens it). PID in DIR/server.pid.
# Launch args: "-jar server.jar" (Fabric, Quilt, Forge 1.21+ shim) or
# "@libraries/net/<loader>/.../unix_args.txt" (Forge 1.20.x, NeoForge) to keep a single JVM.
set -e
dir=$1; port=$2; java=$3; modjar=$4; selftest=$5; shift 6
if lsof -nP -iTCP:$port -sTCP:LISTEN >/dev/null 2>&1; then echo "port $port is busy"; exit 1; fi
cd "$dir"
mkdir -p mods
find mods -maxdepth 1 -name "mctoolkit-bridge*.jar" -delete
cp "$modjar" mods/mctoolkit-bridge.jar
echo "eula=true" > eula.txt
cat > server.properties <<PROPS
server-ip=127.0.0.1
server-port=$port
level-type=minecraft\:flat
view-distance=4
simulation-distance=4
online-mode=false
spawn-protection=0
enable-query=false
enable-rcon=false
generate-structures=false
max-players=5
motd=mctoolkit bridge test
PROPS
rm -rf .toolkit world
# stdin is a FIFO so the harness can type "stop" like the panel does (validate.py --stop console)
rm -f console.fifo; mkfifo console.fifo
nohup "$java" -Xms512M -Xmx1536M -Dmctoolkit.bridge.selftest=$selftest "$@" nogui > server.log 2>&1 < console.fifo &
echo $! > server.pid
disown
echo "started pid $(cat server.pid) on port $port"
