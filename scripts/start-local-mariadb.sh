#!/usr/bin/env bash
# User-local MariaDB for botassistant (port 3307).
# System :3306 needs sudo; this instance lives under ~/botassistant-mysql.
set -euo pipefail
BASE="${BOTASSISTANT_MYSQL_HOME:-$HOME/botassistant-mysql}"
DATADIR="$BASE/data"
SOCK="$BASE/mysql.sock"
PIDF="$BASE/mysql.pid"
LOG="$BASE/mysql.err"
PORT="${BOTASSISTANT_MYSQL_PORT:-3307}"

mkdir -p "$DATADIR"
if [ ! -d "$DATADIR/mysql" ]; then
  mariadb-install-db --user="$USER" --datadir="$DATADIR" --auth-root-authentication-method=normal
fi

if [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; then
  echo "already running pid=$(cat "$PIDF") :$PORT"
  exit 0
fi

mysqld --datadir="$DATADIR" --socket="$SOCK" --pid-file="$PIDF" --port="$PORT" \
  --bind-address=127.0.0.1 --log-error="$LOG" &

for _ in $(seq 1 40); do
  [ -S "$SOCK" ] && break
  sleep 0.25
done
mysql --socket="$SOCK" -u root -e "SELECT VERSION();" >/dev/null
echo "MariaDB ready socket=$SOCK port=$PORT"
