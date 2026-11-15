#!/usr/bin/env bash
# Finish the ZKTeco ADMS integration with Claude Code (CLI).
#   bash claude-zkteco-finish.sh                       # project = ~/Desktop/Attendance, zip = ~/Downloads
#   bash claude-zkteco-finish.sh /path/to/project /path/to/zkteco-adms-laravel.zip
set -euo pipefail

PROJECT="${1:-$HOME/Desktop/Attendance}"
ZIP="${2:-$HOME/Downloads/zkteco-adms-laravel.zip}"
REF=/tmp/zk/zkteco-adms-laravel

command -v claude >/dev/null || { echo "Claude Code CLI not found. Install it, run 'claude' once to log in, then retry."; exit 1; }
cd "$PROJECT"
[ -f artisan ] || { echo "$PROJECT is not a Laravel project"; exit 1; }

# 1) Reference copy of the integration package
[ -f "$ZIP" ] && unzip -oq "$ZIP" -d /tmp/zk
[ -d "$REF" ] || { echo "Package not found: $ZIP"; exit 1; }

# 2) Safety: git checkpoint + database backup (undo = git reset --hard / restore the .sql)
[ -d .git ] || git init -q
git add -A && git -c user.name=checkpoint -c user.email=checkpoint@local commit -qm "checkpoint before zkteco" || true
envv() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true; }
DB=$(envv DB_DATABASE); DBU=$(envv DB_USERNAME); DBP=$(envv DB_PASSWORD); DBH=$(envv DB_HOST)
if command -v mysqldump >/dev/null && [ -n "$DB" ]; then
  OUT="$HOME/${DB}-backup-$(date +%F-%H%M).sql"
  MYSQL_PWD="$DBP" mysqldump -h "${DBH:-127.0.0.1}" -u "$DBU" "$DB" > "$OUT" && echo "DB backup: $OUT" || echo "DB backup skipped (mysqldump failed)"
fi

# 3) Task for Claude
cat > /tmp/zk/TASK.md <<'EOF'
Finish and verify the ZKTeco ADMS (TA PUSH) attendance integration in THIS Laravel project.

Facts:
- Server PC 192.168.10.67, app served with `php artisan serve --host=0.0.0.0 --port=8000` (Apache already uses :80). MySQL.
- Device: ZKTeco SenseFace 3A, SN VGU56244900309, IP 192.168.10.136, protocol TA PUSH -> pushes to /iclock/* on this server.
- Reference implementation (tested): /tmp/zk/zkteco-adms-laravel — read its INSTALL.md first. Current error:
  "Target class [App\Http\Middleware\AuthenticateZktecoDevice] does not exist" (files only partly copied).
- laravel.log also shows: table `employee_device_mappings` doesn't exist (some older attendance/ZKTeco code in this project).

Phase 1 — Analyse (read only), then print a short findings list:
- Laravel/PHP versions, bootstrap/app.php (or Kernel/RouteServiceProvider), frontend stack (Inertia React or Vue, TS?), admin layout + sidebar file, Ziggy, flash sharing in HandleInertiaRequests.
- Employee model/table: which column holds the code that will be typed as "User ID" on the device; any active/status column.
- Every existing attendance/ZKTeco piece: routes touching `iclock`, controllers/models/migrations using `employee_device_mappings`, packages in composer.json. Decide what to keep; ASK me before deleting any existing feature.
- Which package files are missing or differ: `diff -rq /tmp/zk/zkteco-adms-laravel/app app` (same for config, database, routes, resources, tests).

Phase 2 — Integrate:
- Copy missing package files; adapt config/zkteco.php employee mapping to the real Employee model/columns.
- Register `zkteco.device` middleware alias and load routes/iclock.php OUTSIDE the web group (no CSRF/session).
- Paste the admin routes into the existing admin route group (above any hr/attendance resource route); add sidebar links "Machine Attendance" and "Attendance Devices"; share flash success/error if missing.
- If the project uses Vue instead of React, port the two pages to Vue in the project's existing style (dark/light, teal). Fix the AdminLayout import path.
- Schedule `zkteco:housekeeping` hourly. Add to .env (keep everything else untouched):
  ZKTECO_TIMEZONE=Asia/Dhaka, ZKTECO_SERVER_ADDRESS=192.168.10.67, ZKTECO_SERVER_PORT=8000,
  ZKTECO_ALLOWED_IPS=192.168.10.136,192.168.10.67, ZKTECO_QUEUE_CONNECTION=sync, ZKTECO_EMPLOYEE_PIN_COLUMN=<found column>
- Remove/disable duplicate /iclock routes from older code; resolve the employee_device_mappings error (create its migration if that code is still used, otherwise ask me).

Phase 3 — Verify and fix until green:
- composer dump-autoload; php artisan migrate (NEVER migrate:fresh/refresh/reset or db:wipe); php artisan optimize:clear
- php artisan route:list --path=iclock -> only IclockController routes, middleware zkteco.device, no web.
- php artisan zkteco:device VGU56244900309 --name="Main Entrance"
- With the server running on :8000:
  curl -s -H "Accept: application/json" "http://192.168.10.67:8000/iclock/getrequest?SN=VGU56244900309"  -> OK
  bash deploy/simulate-device.sh http://192.168.10.67:8000 VGU56244900309                       -> all HTTP 200
  php artisan zkteco:status -> last seen updated
- Make sure phpunit.xml uses sqlite :memory: for tests, then: php artisan test --filter=Zkteco
- npm run build (and type-check/lint if the project has it) must pass; open both admin routes once (HTTP 200).

Rules: minimal, focused changes; follow the project's code style; never print or change secrets in .env;
ask before deleting files or features. Finish with: files changed, test/verify output, and the exact
device settings (Cloud Server Setting: ADMS, 192.168.10.67, port 8000, domain/proxy/HTTPS OFF).
EOF

# 4) Run Claude Code: file edits auto-accepted, shell commands still ask for approval
exec claude --permission-mode acceptEdits --add-dir /tmp/zk "$(cat /tmp/zk/TASK.md)"