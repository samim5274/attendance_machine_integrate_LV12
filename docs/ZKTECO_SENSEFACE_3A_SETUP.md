# ZKTeco SenseFace 3A — TA PUSH Setup Guide

| | |
|---|---|
| Device | SenseFace 3A · SN `VGU56244900309` · MAC `00:17:61:11:4f:a1` · IP `192.168.10.136` |
| Device port | `4370` (the terminal's own SDK port — **not used by TA PUSH**) |
| Laravel server | `192.168.10.67` (Wi-Fi `wlp2s0`, DHCP) · HTTP port `8000` |
| Protocol | TA PUSH (ADMS): the **device calls the server** over HTTP on `/iclock/*` |

## 1. How TA PUSH works

```
SenseFace 3A ── HTTP ──▶ http://192.168.10.67:8000/iclock/...
   1. GET  /iclock/cdata?SN=VGU56244900309&options=all     handshake → server sends options + upload stamps
   2. POST /iclock/cdata?SN=…&table=ATTLOG&Stamp=…         punches (tab-separated text) → "OK: n"
   3. GET  /iclock/getrequest?SN=…  (every ~10 s)           heartbeat → "OK" or "C:<id>:<command>" lines
   4. POST /iclock/devicecmd?SN=…                            command results "ID=..&Return=..&CMD=.."
```

The server never opens a connection to the device. Port 4370 being open says **nothing** about TA PUSH. The device
counts as **online** only when it has called `/iclock/*` within the last 120 s (`zkteco.devices.online_threshold_seconds`).

## 2. Ubuntu server

### 2.1 Find the LAN IP

```bash
ip -4 -o addr show | awk '{print $2, $4}'      # → wlp2s0 192.168.10.67/24
ip route | grep default                         # → via 192.168.10.1 dev wlp2s0
```

The address comes from DHCP. **Reserve 192.168.10.67 for this PC in the router** (or set a static IP); otherwise the
device loses the server after a lease change. Never use `127.0.0.1` or `localhost` on the device.

### 2.2 Run Laravel on the LAN

```bash
cd ~/Desktop/Attendance
php artisan serve --host=0.0.0.0 --port=8000     # development/testing only
```

`php artisan serve` is a single-threaded development server. For production, use nginx + PHP-FPM
(`deploy/nginx-erp.conf`) or Apache with a dedicated port, plus a queue worker if
`ZKTECO_QUEUE_CONNECTION` is not `sync` (`deploy/supervisor-erp-worker.conf`). Port 80 on this PC is already used by Apache.

### 2.3 Firewall (only the HTTP port, only from the device)

```bash
sudo ufw status
sudo ufw allow from 192.168.10.136 to any port 8000 proto tcp comment 'ZKTeco SenseFace 3A'
# Admin pages from other PCs on the LAN (optional):
sudo ufw allow from 192.168.10.0/24 to any port 8000 proto tcp
```

Do **not** open 4370 on the server; nothing listens there.

### 2.4 `.env` (already set)

```dotenv
ZKTECO_TIMEZONE=Asia/Dhaka
ZKTECO_SERVER_ADDRESS=192.168.10.67      # shown on the Devices page
ZKTECO_SERVER_PORT=8000
ZKTECO_ALLOWED_IPS=192.168.10.136,192.168.10.67   # .67 only for local simulation; remove in production
ZKTECO_QUEUE_CONNECTION=sync
# Optional
ZKTECO_ADMIN_EMAILS=you@example.com      # restrict admin pages (empty = any logged-in user)
ZKTECO_GLOBAL_PIN_FALLBACK=true          # also accept employees.biometric_id when no mapping exists
ZKTECO_MAX_BODY_KB=4096
ZKTECO_RATE_LIMIT=600                    # requests/min per IP on /iclock
ZKTECO_MAX_FUTURE_MINUTES=1440
```

After editing `.env`: `php artisan config:clear`.

### 2.5 Admin login

Admin pages use HTTP Basic auth against the `users` table. The database has no users yet, so create one:

```bash
php artisan zkteco:admin-user you@example.com --name="HR Admin"   # asks for the password
```

Then open `http://192.168.10.67:8000/admin/hr/attendance/devices`. The browser will show a login prompt.

## 3. Device configuration (on the terminal)

`Menu → COMM. → Cloud Server Setting`

| Setting | Value |
|---|---|
| Server Mode | **ADMS** |
| Enable Domain Name | OFF |
| Server Address | **192.168.10.67** |
| Server Port | **8000** (Laravel HTTP port, not 4370) |
| Enable Proxy Server | OFF |
| HTTPS | OFF |

Also check:

- `Menu → COMM. → Ethernet`: IP 192.168.10.136, mask 255.255.255.0, gateway 192.168.10.1, the same subnet as the server.
- `Menu → System → Date Time`: correct date and time. Punches dated before 2000 or more than 24 h in the future are rejected as clock errors.
- The firmware must be **T&A PUSH** (attendance). If the server log shows `/iclock/registry` or `/iclock/push`, the
  terminal runs **access-control** push firmware. The Devices page then shows a warning, and attendance will not upload.
- After saving, the cloud icon on the terminal's main screen should become connected within about 30 s.

## 4. Register the device (already done; safe to repeat)

```bash
php artisan zkteco:device VGU56244900309 --name="Main Device" --model="SenseFace 3A" \
    --mac=00:17:61:11:4f:a1 --ip=192.168.10.136 --port=4370
```

The serial number is the device's identity. Running this again updates the existing row and never creates a
duplicate. The same applies to the "Add device" form, which refuses an existing serial. An unknown terminal
that connects by itself appears on the Devices page as "waiting for approval", and its data is refused until you
approve it.

## 5. Employee PIN mapping

The **machine PIN** is the *User ID* of the person on the terminal (`Menu → User Mgt. → All Users`). It is
**not** assumed to equal the employee ID (`employees.id`) or the employee code (`EMP-1001`).

Admin → **PIN Mapping** → *Add mapping*: device, employee, machine PIN, active.

- One PIN can belong to only one employee per device. One employee can have PINs on several devices.
- When a punch arrives, the server resolves it in this order:
  1. an active mapping for that device and PIN;
  2. an inactive mapping, which leaves the punch unresolved on purpose;
  3. the legacy `employees.biometric_id` column, if `ZKTECO_GLOBAL_PIN_FALLBACK=true`;
  4. otherwise the punch is stored **unresolved** (`employee_id = NULL`).
- Unresolved punches are never discarded. They are listed under Machine Attendance → **Unmapped PINs**, where
  **Map** opens the mapping form pre-filled. Saving the mapping links the earlier punches and recalculates attendance.
- Punches that are already linked are never moved to another employee automatically, even when a mapping is edited.
- Per mapping, **Device → Push user to device** queues `DATA UPDATE USERINFO` (the user with the same PIN and name), and
  **Delete user from device** queues `DATA DELETE USERINFO`. Faces still have to be enrolled on the terminal itself.

## 6. Attendance data flow

1. The device POSTs `ATTLOG` lines: `PIN \t YYYY-MM-DD HH:MM:SS \t status \t verify \t workcode …`.
2. Each line is validated: a real calendar date, PIN ≠ 0, and the clock-sanity window. Invalid lines are logged to
   `storage/logs/zkteco-*.log` with their raw text.
3. Device-local time (`Asia/Dhaka`, or the device's own time zone) is converted to the app time zone.
4. Lines are stored in `attendance_punches` with the original PIN, status, verify mode, work code and raw line. The unique key
   `(device_sn, pin, punched_at)` plus `insertOrIgnore` makes repeated or offline uploads idempotent.
5. Only after the commit does the server answer `OK: n`. On any error it answers 500, and the device keeps the data and retries.
6. For each affected employee, the daily row (`employee_daily_attendances`) is rebuilt: first IN / last OUT, repeated
   taps within 2 min counted once, night shifts across midnight, shift and late minutes.
   Rows with `is_locked = 1` or `source = manual` are **never overwritten**.

Historical logs: Devices page → **Re-upload ALL logs** (resets the upload stamps and sends `CHECK`; duplicates are ignored),
or **Pull by date** (`DATA QUERY ATTLOG StartTime=…`). The date query is not supported by every firmware. If it shows
*Failed (-1002)*, use Re-upload ALL.

## 7. Device commands

Devices page → card menu or footer buttons. Commands wait in `attendance_device_commands` and are handed to the device on
its next `/getrequest`. They move from **Waiting** to **Sent** to **Done** (the device returned `Return>=0`), **Failed**
(negative return code, shown), or **No answer** (not acknowledged within 30 min). A queued command is never shown as done.

| Button | Protocol command |
|---|---|
| Test connection | reports last contact, queues `INFO` to prove the round trip |
| Refresh device info | `INFO` |
| Upload new logs | `CHECK` |
| Re-upload ALL logs | stamps reset + `CHECK` |
| Pull logs for a period | `DATA QUERY ATTLOG StartTime=…\tEndTime=…` (firmware-dependent) |
| Read user list | `DATA QUERY USERINFO` |
| Push employees | `DATA UPDATE USERINFO` per mapped employee (plus legacy `biometric_id` employees) |
| Reboot | `REBOOT` |
| Command history | per-device list with the device's reply |

Raw protocol commands are **CLI only**: `php artisan zkteco:send VGU56244900309 raw --raw="…"`.

## 8. Security summary

- `/iclock/*`: no session or CSRF; the serial must be registered **and** active; optional IP allow-list; 600 req/min/IP
  rate limit; 4 MB body limit (413); plain-text replies; raw bodies are logged only if `ZKTECO_LOG_RAW=true`.
- Biometric templates and photos are not requested or stored.
- Admin: `auth.basic` + gate `manage-attendance-devices` (optional e-mail allow-list); command endpoints are throttled 20/min.
- PINs and names sent to the device are sanitised; tabs and newlines cannot inject extra commands.

## 9. Testing & troubleshooting

```bash
php artisan zkteco:status                     # state, last seen/handshake, punches today
tail -f storage/logs/zkteco-$(date +%F).log   # every handshake, upload, command
php artisan test --filter=Zkteco              # automated tests (sqlite in memory)
php artisan route:list --path=iclock
bash deploy/simulate-device.sh http://192.168.10.67:8000 VGU56244900309 99999   # fake device (marks it "seen"!)
```

Reachability from another LAN machine: `curl "http://192.168.10.67:8000/iclock/getrequest"` should return
`ERROR: SN required` (HTTP 400). That proves the HTTP path works without writing anything.

| Symptom | Cause / fix |
|---|---|
| Device stays "never seen" | Server Address/Port wrong on the device, `php artisan serve` bound to 127.0.0.1, ufw blocking 8000, or the server IP changed (DHCP). |
| Device in "waiting for approval" | Serial not registered or typed differently. Approve it on the Devices page. |
| Log: `Rejected request from IP not in allow-list` | Add the device IP to `ZKTECO_ALLOWED_IPS`, or empty it. |
| Log: `DEVICE DISABLED` (403) | Enable the device on the Devices page. |
| Online but no punches | Firmware is AC push (see the warning), or the device clock is wrong (see the "impossible timestamp" log lines). |
| Punches but no attendance rows | PIN not mapped. Machine Attendance → Unmapped PINs → **Map**. |
| Command stays "Waiting" | Device offline. It runs on the next poll; "No answer" after 30 min. |
| Command "Failed (-1002)" | Command not supported by this firmware. |
| Times off by hours | The device clock time zone differs: set the device's time zone on the Devices page. |
| 401 on admin pages | Create a user: `php artisan zkteco:admin-user …`. 403: e-mail not in `ZKTECO_ADMIN_EMAILS`. |
| 429 / 413 from /iclock | Rate or body limit. Raise `ZKTECO_RATE_LIMIT` / `ZKTECO_MAX_BODY_KB`. |
