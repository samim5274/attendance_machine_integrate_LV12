<?php

/*
|--------------------------------------------------------------------------
| ZKTeco ADMS (TA PUSH) integration
|--------------------------------------------------------------------------
|
| The attendance terminal (SenseFace 3A, "TA PUSH" firmware) opens HTTP
| connections to this Laravel app on /iclock/* — the app never connects to
| the device. Configure the device under:
|   Menu > COMM. > Cloud Server Setting
|     Server Mode .......... ADMS
|     Enable Domain Name ... OFF
|     Server Address ....... <LAN IP of this server, e.g. 192.168.10.20>
|     Server Port .......... <port nginx listens on, e.g. 80 or 8081>
|     Enable Proxy Server .. OFF
|     HTTPS ................ OFF
|
*/

return [

    // Master switch. When false every /iclock request is answered with 503
    // and the device keeps its records until you switch it back on.
    'enabled' => env('ZKTECO_ENABLED', true),

    // Time zone the device clock runs in. Punch times arrive as local wall
    // clock time; they are converted to config('app.timezone') for storage.
    'timezone' => env('ZKTECO_TIMEZONE', 'Asia/Dhaka'),

    // Offset (hours) sent to the device in the handshake. null = derive from
    // the time zone above (Asia/Dhaka => 6).
    'timezone_offset' => env('ZKTECO_TZ_OFFSET'),

    // Unknown serial numbers are recorded as *inactive* devices so an admin
    // can approve them from the Devices page. Their data is NOT accepted
    // (the device keeps it and re-sends after approval).
    'auto_register' => env('ZKTECO_AUTO_REGISTER', true),
    'max_pending_devices' => 10,

    // Optional source IP allow-list (comma separated IPs or CIDR ranges).
    // Example: ZKTECO_ALLOWED_IPS=192.168.10.0/24
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ZKTECO_ALLOWED_IPS', ''))))),

    // Largest request body accepted from a terminal (KB). Bulk re-uploads are
    // split into many requests by the device, so a few MB is plenty.
    'max_body_kb' => (int) env('ZKTECO_MAX_BODY_KB', 4096),

    // Requests per minute per source IP on /iclock/* (a device polls every
    // ~10 s and uploads in bursts; this only stops floods).
    'rate_limit_per_minute' => (int) env('ZKTECO_RATE_LIMIT', 600),

    // Who may use the admin pages (Devices, PIN mapping, Machine attendance).
    // Empty = every logged-in user. Comma separated e-mail addresses otherwise.
    'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ZKTECO_ADMIN_EMAILS', ''))))),

    // Address shown on the Devices page as "what to type into the device".
    'server_address_hint' => env('ZKTECO_SERVER_ADDRESS'),
    'server_port_hint' => env('ZKTECO_SERVER_PORT'),

    'handshake' => [
        'delay' => 10,                 // seconds between /iclock/getrequest polls
        'error_delay' => 30,           // retry delay after a failed request
        'realtime' => 1,               // 1 = upload each punch immediately
        'trans_times' => '00:00;14:05',// scheduled bulk uploads (safety net)
        'trans_interval' => 1,         // minutes between change checks
        // What the device uploads: punches + user names. Face/finger templates
        // and photos are deliberately NOT requested — biometric data never
        // leaves the terminal (privacy, bandwidth). Add "\tAttPhoto" only if you
        // really need punch snapshots.
        'trans_flag' => "TransData AttLog\tOpLog\tEnrollUser\tChgUser",
        // Extra raw "Key=Value" lines appended to the handshake, if a
        // firmware needs them (e.g. 'ServerVer' => '2.4.1').
        'extra' => [],
    ],

    'devices' => [
        // A device is "online" if it polled within this many seconds.
        'online_threshold_seconds' => 120,
        // Avoid a DB write on every 10s poll; refresh last_seen_at at most this often.
        'last_seen_write_interval' => 30,
        // Max commands handed over in one /getrequest reply.
        'commands_per_poll' => 10,
        // Commands sent but never acknowledged become "expired" after this.
        'command_timeout_minutes' => 30,
    ],

    /*
    | How a device user (PIN / "User ID" on the terminal) maps to employees.
    |  1. Per-device mapping (Admin -> PIN Mapping, table employee_device_mappings)
    |     always wins; an INACTIVE mapping leaves the PIN unresolved.
    |  2. Legacy fallback: the global pin_column (employees.biometric_id). Turn it
    |     off with ZKTECO_GLOBAL_PIN_FALLBACK=false to rely on mappings only.
    | The PIN is never assumed to equal the employee's id or employee code.
    */
    'employee' => [
        'model' => App\Models\Employee::class,
        'pin_column' => env('ZKTECO_EMPLOYEE_PIN_COLUMN', 'biometric_id'),
        'global_pin_fallback' => env('ZKTECO_GLOBAL_PIN_FALLBACK', true),
        'name_column' => env('ZKTECO_EMPLOYEE_NAME_COLUMN', 'name'),
        // Optional: column + value that marks an employee as active, used to
        // build the daily sheet and when pushing users to the device.
        'active_column' => env('ZKTECO_EMPLOYEE_ACTIVE_COLUMN', 'status'),
        'active_value' => env('ZKTECO_EMPLOYEE_ACTIVE_VALUE', 'active'),
    ],

    'attendance' => [
        // A punch more than this many hours after the first punch of the
        // current duty starts a new duty. 16h covers 12h night shifts that
        // cross midnight (20:00 -> 08:00 is one duty, dated on the IN day).
        'max_shift_hours' => 16,

        // Repeated punches within this window count as one.
        'duplicate_punch_minutes' => 2,

        // Shift detection (no roster needed). For the first punch of a duty:
        //  1. if it is on time (<= start + grace) for one or more shifts
        //     within match_window_minutes, the nearest such shift is used;
        //  2. otherwise the most recently started shift is used and the
        //     minutes after its start are recorded as late.
        // The rule is lenient on purpose: it never marks someone late when
        // the punch is on time for *some* shift. Edit to match your rosters,
        // or set to [] to disable shift/late calculation.
        'shifts' => [
            ['name' => 'Morning', 'start' => '08:00'],
            ['name' => 'Office',  'start' => '09:00'],
            ['name' => 'Evening', 'start' => '14:00'],
            ['name' => 'Night',   'start' => '20:00'],
        ],
        'late_grace_minutes' => 10,
        'match_window_minutes' => 180,

        // Punches outside [earliest_valid_date, now + max_future_minutes] are
        // treated as a wrong device clock: logged, not stored.
        'earliest_valid_date' => env('ZKTECO_EARLIEST_VALID_DATE', '2000-01-01'),
        'max_future_minutes' => (int) env('ZKTECO_MAX_FUTURE_MINUTES', 1440),
    ],

    // Queue for the daily-attendance rebuild jobs. Set ZKTECO_QUEUE_CONNECTION=sync
    // to rebuild inline (no queue worker needed — fine for a few hundred staff).
    'queue_connection' => env('ZKTECO_QUEUE_CONNECTION'),
    'queue' => env('ZKTECO_QUEUE', 'default'),

    // Dedicated log file: storage/logs/zkteco-YYYY-MM-DD.log
    'log' => [
        'enabled' => env('ZKTECO_LOG', true),
        'raw_bodies' => env('ZKTECO_LOG_RAW', false), // log every request body (debug only)
        'days' => 30,
    ],
];
