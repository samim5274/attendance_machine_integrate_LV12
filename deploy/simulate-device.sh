#!/usr/bin/env bash
# Pretend to be the ZKTeco terminal — tests the server side WITHOUT the real device.
#
#   bash deploy/simulate-device.sh http://192.168.10.20 VGU56244900309 99999
#
# Arg 1: base URL of the Laravel server (what the device would use)
# Arg 2: device serial number (must be registered + active, or it shows up as "pending")
# Arg 3: User ID to punch for. Keep the default 99999 (no employee has it) so real
#        attendance is not touched — the punch appears under "Unmapped PINs".
set -euo pipefail

BASE="${1:-http://127.0.0.1}"
SN="${2:-VGU56244900309}"
PIN="${3:-99999}"
NOW="$(TZ=Asia/Dhaka date '+%Y-%m-%d %H:%M:%S')"

hr() { printf '\n\033[1;36m== %s\033[0m\n' "$1"; }

hr "1. Handshake (GET /iclock/cdata?options=all) — expect 'GET OPTION FROM: $SN'"
curl -sS -w '\n[HTTP %{http_code}]\n' "$BASE/iclock/cdata?SN=$SN&options=all&pushver=2.4.1&language=69"

hr "2. Punch upload (POST table=ATTLOG) — expect 'OK: 1'"
curl -sS -w '\n[HTTP %{http_code}]\n' -X POST -H 'Content-Type: text/plain' \
  --data-binary "$(printf '%s\t%s\t0\t15\t\t0\t0\n' "$PIN" "$NOW")" \
  "$BASE/iclock/cdata?SN=$SN&table=ATTLOG&Stamp=1"

hr "3. Same punch again — must be ignored (idempotent), still 'OK: 1'"
curl -sS -w '\n[HTTP %{http_code}]\n' -X POST -H 'Content-Type: text/plain' \
  --data-binary "$(printf '%s\t%s\t0\t15\t\t0\t0\n' "$PIN" "$NOW")" \
  "$BASE/iclock/cdata?SN=$SN&table=ATTLOG&Stamp=1"

hr "4. Heartbeat (GET /iclock/getrequest) — 'OK' or queued 'C:<id>:<command>' lines"
curl -sS -w '\n[HTTP %{http_code}]\n' "$BASE/iclock/getrequest?SN=$SN"

echo
echo "Open Admin → HR → Machine Attendance → Punch log: PIN $PIN at ${NOW:11:5} should be there"
echo "(under 'Unmapped PINs' if no employee has code $PIN). Server log: storage/logs/zkteco-$(date +%F).log"
