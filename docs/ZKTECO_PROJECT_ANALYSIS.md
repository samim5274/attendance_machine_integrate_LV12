# ZKTeco SenseFace 3A — Project Analysis & Implementation Decisions

Analysis date: 2026-11-16. Scope: the whole Laravel project in `~/Desktop/Attendance`.

## 1. Environment discovered

| Item | Value |
|---|---|
| PHP | 8.4.27 |
| Laravel | 12.72.0 (`bootstrap/app.php` style, no Kernel) |
| Packages | `laravel/framework`, `laravel/tinker` only. No ZKTeco SDK package, no auth/permission package. Dev: phpunit 11, pint. |
| Database | MySQL `attendace_db` (app); tests use sqlite `:memory:` (phpunit.xml) |
| Queue | `QUEUE_CONNECTION=database`, but `ZKTECO_QUEUE_CONNECTION=sync` → attendance rebuild runs inline |
| Scheduler | `routes/console.php`: `zkteco:housekeeping` hourly |
| Frontend | Blade + Bootstrap 5 + bootstrap-icons via Vite (`resources/css/admin.css`, `resources/js/admin.js`). Tailwind only on `welcome`. |
| Server LAN IP | `192.168.10.67/24` on `wlp2s0` (DHCP, gateway 192.168.10.1) |
| HTTP | `php artisan serve` on `0.0.0.0:8000` (running); Apache already owns `:80` |
| Device | SenseFace 3A, SN `VGU56244900309`, IP `192.168.10.136` (answers ping from the server) |

## 2. Existing architecture

The project is a small, attendance-only Laravel app (employees + ZKTeco). There is **no payroll module, no
`employee_attendances` table, and no reports other than the Machine Attendance screen**. The ZKTeco integration was
added in a previous session from a reference package; an even older, partial implementation was removed in the
working tree (staged deletions, see §6).

```
Device (TA PUSH / ADMS) ──HTTP──▶ /iclock/*  (routes/iclock.php, no web/CSRF/session)
                                   │  middleware zkteco.device  (AuthenticateZktecoDevice)
                                   │     enabled? IP allow-list? SN known? active? → touch last_seen_at
                                   ▼
                          Zkteco\IclockController
          GET  cdata?options=all → handshake (AdmsProtocol::handshake)
          POST cdata?table=ATTLOG → AdmsParser::attLog → AttendanceIngestService::storePunches
          POST cdata?table=OPERLOG/USERINFO → device users (no biometric templates)
          GET  getrequest        → INFO counters + hand over queued commands (C:<id>:<cmd>)
          POST devicecmd         → command results (ID=..&Return=..&CMD=..)
                                   ▼
          attendance_punches (raw, immutable, unique device_sn+pin+punched_at)
                                   ▼  RebuildDailyAttendance job (sync)
          DailyAttendanceBuilder → employee_daily_attendances (first IN / last OUT, shifts, late,
                                   night shifts across midnight, locked/manual rows never overwritten)
```

Admin side (`routes/web.php`, prefix `admin/hr/attendance`):
`MachineAttendanceController` (daily sheet, punch log, unmapped PINs, CSV export, re-link) and
`AttendanceDeviceController` (device CRUD, command queue). Artisan: `zkteco:device`, `zkteco:send`,
`zkteco:status`, `zkteco:rebuild`, `zkteco:housekeeping`.

### Requested names vs. what exists

| Requested | Exists as |
|---|---|
| AttendanceDevice | `App\Models\AttendanceDevice`, table `attendance_devices` |
| AttendanceDeviceCommand | `App\Models\AttendanceDeviceCommand`, table `attendance_device_commands` |
| DeviceCommandService | `App\Services\Zkteco\DeviceCommandService` |
| BiometricAttendanceLog | **Replaced** by `App\Models\AttendancePunch` (`attendance_punches`). Old model deleted, table never existed in the DB. |
| EmployeeDeviceMapping | **Missing.** Old model/controller/views are staged for deletion; the table `employee_device_mappings` was never migrated (the source of the "table doesn't exist" errors in `laravel.log`). |
| `device_user_id` / PIN | `attendance_punches.pin`, `attendance_device_users.pin` |
| `punched_at` | `attendance_punches.punched_at` (stored in app time zone) |

## 3. Database (all 10 migrations ran, batch 1)

| Table | Key columns / constraints |
|---|---|
| `employees` | `employee_id` (code, e.g. `EMP-1001`, unique), `biometric_id` (nullable, unique; added by `…100005`), `status` enum active/inactive, soft deletes. 10 rows, `biometric_id` NULL for all. |
| `attendance_devices` | `serial_number` unique, name, model, location, ip_address, mac_address, firmware_version, push_version, timezone, is_active, stamps, counters, options JSON, last_seen_at, last_handshake_at, last_punch_at. **No `port`, no `protocol`.** 1 row: `VGU56244900309` "Main Device", active, never seen. |
| `attendance_punches` | FK device (null on delete), device_sn, pin, employee_id (nullable = unresolved), punched_at, attendance_date, status, verify_type, work_code, raw (line), received_at. Unique `(device_sn, pin, punched_at)`. 0 rows. |
| `attendance_device_users` | users reported by each terminal; unique `(device, pin)` |
| `attendance_device_commands` | FK device (cascade), type, command, status pending/sent/success/failed/expired, return_code, response, requested_by, sent_at, completed_at |
| `employee_daily_attendances` | unique `(employee_id, attendance_date)`, `source` device/manual, `is_locked` |
| `users` | 0 rows — **nobody can log in; no login exists** |

## 4. Existing device communication — protocol check

The full "Attendance PUSH Communication Protocol" PDF is distributed by ZKTeco on request and could not be
retrieved publicly. ZKTeco's public overview confirms that T&A PUSH (HTTP, device-initiated) is distinct from
AC/"Security" PUSH and needs attendance firmware. The request/response formats below were cross-checked against
that overview and several independent open-source ADMS servers. They match what the project implements:

| Endpoint | Implemented behaviour | Confidence |
|---|---|---|
| `GET /iclock/cdata?SN=&options=all&pushver=` | `GET OPTION FROM: SN` + `Stamp/OpStamp/ATTLOGStamp…`, `ErrorDelay`, `Delay`, `TransTimes`, `TransInterval`, `TransFlag`, `Realtime`, `TimeZone`, `Encrypt=None` (text/plain) | High (common to all published servers) |
| `POST /iclock/cdata?SN=&table=ATTLOG&Stamp=` | tab-separated lines `PIN, YYYY-MM-DD HH:MM:SS, status, verify, workcode, …`; reply `OK: <n>` | High |
| `POST …table=OPERLOG` / `USERINFO` | `USER PIN=..\tName=..` lines, `FP`/`FACE`/`BIODATA` counters only | High for USER; template lines vary |
| `GET /iclock/getrequest?SN=&INFO=` | `OK` or `C:<id>:<command>` lines | High |
| `POST /iclock/devicecmd` | `ID=<id>&Return=<code>&CMD=<cmd>`; `Return>=0` = success | High |
| `CHECK`, `INFO`, `REBOOT`, `DATA UPDATE USERINFO`, `DATA DELETE USERINFO`, `DATA QUERY USERINFO` | queued commands | High (widely used) |
| `DATA QUERY ATTLOG StartTime=..\tEndTime=..` | "pull logs for a period" | **Unverified — firmware dependent.** UI already explains that `Return=-1002` means unsupported and to use "Re-upload ALL logs". |
| `/iclock/rtdata?type=time` | packed `DateTime=` reply | Medium (newer firmwares only) |

The real device has **never contacted** this server: `zkteco-2026-11-15.log` only shows a simulated handshake from
`192.168.10.67` (the server itself). The device-side Cloud Server Setting still needs to be checked (see setup doc).

## 5. Gaps, bugs, security issues

| # | Finding | Severity |
|---|---|---|
| G1 | **Per-device PIN mapping missing.** PIN → employee uses one global column `employees.biometric_id`, so a PIN can't differ per device. Requirement: `(device, PIN) → employee`, one employee on many devices, never assume PIN = employee ID/code. | High |
| G2 | Daily sheet / push-employees / CSV only consider employees with `biometric_id`; mapped-only employees would be invisible. | High (follows G1) |
| G3 | "Unmapped PINs" groups by PIN only, ignoring the device. | Medium |
| G4 | `attendance_devices` has no `port` / `protocol`; device form can't set model, MAC, IP. | Medium |
| S1 | **Admin routes are public** (no `auth`, no authorization). Anyone on the LAN can reboot the device, delete devices, push users. | High |
| S2 | No rate limit or payload-size limit on `/iclock/*`. | Medium |
| S3 | `deleteUser()` accepts an empty PIN (`DATA DELETE USERINFO PIN=`). | Medium |
| S4 | Unknown serials are auto-registered as *inactive* (capped at 10) and their data refused. Acceptable; kept. | OK |
| B1 | Timestamps are format-checked but absurd dates (e.g. year 2000 after a clock reset, far future) are accepted. | Medium |
| B2 | Device page shows no port/protocol/last handshake/total received logs; command history is global and has no per-device filter or response. | Low |
| D1 | `routes/snippets/zkteco-admin-routes.php` duplicates admin routes (not loaded — harmless, kept as reference). | Info |
| D2 | Stray file `t('id')` in project root (tinker output accidentally committed). Not touched. | Info |
| D3 | Older code (`ZktecoAdmsController`, `BiometricAttendanceLog`, `EmployeeDeviceMapping`, `ProcessZktecoAttendanceBatch`, `ZktecoAttendanceProcessor`, `ZktecoDailyAttendanceSync` → non-existent `EmployeeAttendance` model, React `.tsx` pages) is staged for deletion. It duplicated `/iclock` routes and referenced tables/models that never existed. Deletion kept; the mapping concept is re-implemented properly. | Info |

## 6. Implementation decisions

1. **Keep** the current TA PUSH stack (`IclockController`, middleware, parser, ingest, builder, command service).
   It is working and tested; nothing is rebuilt.
2. **New table `employee_device_mappings`** (`attendance_device_id`, `employee_id`, `device_user_id`, `is_active`),
   unique `(attendance_device_id, device_user_id)`, FKs to devices and employees. Column `attendance_device_id`
   follows the existing convention (`attendance_punches`, `attendance_device_users`, `…_commands`).
   New model `EmployeeDeviceMapping`, relations on `Employee` and `AttendanceDevice`.
3. **Resolution order** in `EmployeeResolver` (device aware):
   1. an **active** mapping for (device, PIN) → that employee;
   2. an **inactive** mapping for (device, PIN) → unresolved (deactivation is deliberate; no fallback);
   3. otherwise the legacy global `employees.biometric_id` (existing feature, kept; can be switched off with
      `ZKTECO_GLOBAL_PIN_FALLBACK=false`);
   4. otherwise unresolved (`employee_id = NULL`). The punch is still stored, and the PIN is kept.
4. Creating/editing a mapping links that device's **unresolved** punches for the PIN and rebuilds daily attendance.
   Punches already linked to an employee are **never re-assigned automatically** (protects corrected/locked data).
5. **Auth**: admin routes get `auth.basic` + `can:manage-attendance-devices`. Laravel built-in, no login page needed;
   a new `zkteco:admin-user` command creates the first user. The gate allows any authenticated user unless
   `ZKTECO_ADMIN_EMAILS` restricts it.
6. **Device endpoint hardening**: per-IP rate limiter `zkteco` and a body-size limit (`ZKTECO_MAX_BODY_KB`) in the device middleware.
7. **Timestamp sanity**: punches before `2000-01-01` or more than `ZKTECO_MAX_FUTURE_MINUTES` (default 1440)
   ahead of server time are skipped and logged (with the raw line), never stored as attendance.
8. **Commands**: raw commands stay CLI-only (`zkteco:send … raw`). The web UI can additionally run
   `push_user` / `delete_user` for a single mapping with a validated PIN. A command is only shown as "Done" after the
   device returns `Return>=0` on `/devicecmd`.
9. Migrations: two new ones only; no executed migration is edited.

## 7. Files to change

Modified: `app/Services/Zkteco/{EmployeeResolver,AttendanceIngestService,DeviceCommandService,AdmsParser}.php`,
`app/Http/Middleware/AuthenticateZktecoDevice.php`, `app/Http/Controllers/Admin/Hr/{AttendanceDeviceController,MachineAttendanceController}.php`,
`app/Models/{AttendanceDevice,Employee}.php`, `app/Console/Commands/Zkteco/{ZktecoDevice,ZktecoStatus}.php`,
`app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/zkteco.php`, `routes/web.php`,
`resources/views/admin/layouts/master.blade.php`, `resources/views/admin/hr/attendance/{devices,machine}.blade.php`,
`tests/Feature/Zkteco/ZktecoAdminPagesTest.php`.

New: migrations `2026_11_16_000001_add_port_and_protocol_to_attendance_devices_table`,
`2026_11_16_000002_create_employee_device_mappings_table`; `app/Models/EmployeeDeviceMapping.php`;
`app/Http/Controllers/Admin/Hr/EmployeeDeviceMappingController.php`;
`resources/views/admin/hr/attendance/mappings.blade.php`; `app/Console/Commands/Zkteco/ZktecoAdminUser.php`;
`tests/Feature/Zkteco/ZktecoMappingTest.php`; `docs/ZKTECO_SENSEFACE_3A_SETUP.md`.

## Sources consulted

- ZKTeco, *A brief introduction to the PUSH protocol* (T&A vs AC push): https://tecnosinergia.zendesk.com/hc/es/article_attachments/4418453804187
- ZKTeco, *General Introduction of PUSH SDK Protocol* (403 from this network): https://cdn.tvc.mx/media/92189/General-Introduction-of-PUSH-SDK-Protocol.pdf
- Independent ADMS implementations used for cross-checking formats: https://github.com/s0x90/zkteco-adms ,
  https://packalyst.com/packages/package/athwari/laravel-zkteco-adms-server
