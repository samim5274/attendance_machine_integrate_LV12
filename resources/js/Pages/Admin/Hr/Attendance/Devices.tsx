import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    Cpu,
    Download,
    Fingerprint,
    Info,
    MapPin,
    MoreVertical,
    Pencil,
    Plus,
    Power,
    RefreshCw,
    RotateCcw,
    ScanFace,
    Server,
    Trash2,
    Upload,
    Users,
    X,
} from 'lucide-react';
import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';

// Ziggy's global route() helper.
declare function route(name: string, params?: Record<string, unknown> | number | string): string;

/* ------------------------------------------------------------------ types */

interface Device {
    id: number;
    name: string;
    serial_number: string;
    model: string | null;
    location: string | null;
    ip_address: string | null;
    mac_address: string | null;
    firmware_version: string | null;
    push_version: string | null;
    timezone: string | null;
    is_active: boolean;
    is_pending: boolean;
    online: boolean;
    last_seen_at: string | null;
    last_seen_human: string | null;
    last_punch_at: string | null;
    user_count: number | null;
    face_count: number | null;
    fp_count: number | null;
    attlog_count: number | null;
    today_punches: number;
    enrolled_users: number;
    pending_commands: number;
    protocol_warning: string | null;
    notes: string | null;
}

interface CommandRow {
    id: number;
    device: string | null;
    type: string;
    label: string;
    command: string;
    status: 'pending' | 'sent' | 'success' | 'failed' | 'expired';
    return_code: number | null;
    created_human: string | null;
    completed_at: string | null;
}

type CommandType = 'info' | 'check' | 'resync_all' | 'sync_logs' | 'sync_users' | 'push_employees' | 'reboot';

interface Props {
    devices: Device[];
    commands: CommandRow[];
    server: { address: string; port: number; timezone: string; online_threshold: number };
    commandOptions: { value: CommandType; label: string }[];
}

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ');

const COMMAND_META: Record<CommandType, { icon: ReactNode; help: string; confirm?: string; danger?: boolean }> = {
    info: { icon: <Info className="h-4 w-4" />, help: 'Read firmware, counters, MAC' },
    check: { icon: <Upload className="h-4 w-4" />, help: 'Upload anything not yet sent' },
    sync_logs: { icon: <Download className="h-4 w-4" />, help: 'Pull logs between two dates' },
    resync_all: {
        icon: <RotateCcw className="h-4 w-4" />,
        help: 'Re-send every stored log',
        confirm: 'The terminal will re-upload every attendance log it has stored. Duplicates are ignored, so nothing is double-counted, but it can take a few minutes on large devices.',
    },
    sync_users: { icon: <Users className="h-4 w-4" />, help: 'Names & IDs enrolled on the device' },
    push_employees: {
        icon: <ScanFace className="h-4 w-4" />,
        help: 'Create every employee on the device',
        confirm: 'Every active employee with an employee code will be created/updated on the terminal (User ID = employee code). Faces still have to be enrolled on the device itself.',
    },
    reboot: {
        icon: <Power className="h-4 w-4" />,
        help: 'Restart the terminal',
        confirm: 'The terminal will restart and be unavailable for about a minute. No data is lost.',
        danger: true,
    },
};

/* ------------------------------------------------------------- component */

export default function Devices({ devices, commands, server, commandOptions }: Props) {
    const flash = (usePage().props as { flash?: { success?: string; error?: string } }).flash;
    const [editing, setEditing] = useState<Device | 'new' | null>(null);
    const [confirm, setConfirm] = useState<{ device: Device; type: CommandType } | null>(null);
    const [syncFor, setSyncFor] = useState<Device | null>(null);
    const [deleting, setDeleting] = useState<Device | null>(null);
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    // Keep status fresh while the page is open.
    useEffect(() => {
        const id = setInterval(() => router.reload({ only: ['devices', 'commands'] }), 15000);
        return () => clearInterval(id);
    }, []);

    useEffect(() => {
        if (flash?.success) setToast({ type: 'success', text: flash.success });
        else if (flash?.error) setToast({ type: 'error', text: flash.error });
    }, [flash?.success, flash?.error]);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), 6000);
        return () => clearTimeout(t);
    }, [toast]);

    const send = (device: Device, type: CommandType, extra: Record<string, string> = {}) =>
        router.post(route('admin.hr.attendance.devices.command', device.id), { type, ...extra }, { preserveScroll: true });

    const runCommand = (device: Device, type: CommandType) => {
        if (type === 'sync_logs') return setSyncFor(device);
        if (COMMAND_META[type].confirm) return setConfirm({ device, type });
        send(device, type);
    };

    const activate = (d: Device) =>
        router.put(
            route('admin.hr.attendance.devices.update', d.id),
            { name: d.name.startsWith('New device') ? `Attendance ${d.serial_number}` : d.name, location: d.location, timezone: d.timezone, notes: d.notes, is_active: true },
            { preserveScroll: true },
        );

    const online = devices.filter((d) => d.online).length;
    const pending = devices.filter((d) => d.is_pending);
    const regular = devices.filter((d) => !d.is_pending);

    return (
        <AdminLayout>
            <Head title="Attendance Devices" />

            <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Link
                            href={route('admin.hr.attendance.machine.index')}
                            className="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-teal-600 hover:underline dark:text-teal-400"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" /> Machine attendance
                        </Link>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Attendance Devices</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {devices.length} device(s) · {online} online · ZKTeco ADMS (TA PUSH)
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setEditing('new')}
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 lg:self-auto"
                    >
                        <Plus className="h-4 w-4" /> Add device
                    </button>
                </div>

                <SetupCard server={server} />

                {/* Pending approval */}
                {pending.length > 0 && (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <div className="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200">
                            <AlertTriangle className="h-4 w-4" /> New terminal(s) waiting for approval
                        </div>
                        <p className="mt-1 text-sm text-amber-800/90 dark:text-amber-200/80">
                            These connected to the server but are not trusted yet. Their data stays on the device until you approve them.
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {pending.map((d) => (
                                <div key={d.id} className="flex items-center gap-3 rounded-xl border border-amber-200 bg-white px-3 py-2 dark:border-amber-500/20 dark:bg-slate-900">
                                    <div>
                                        <div className="font-mono text-sm font-semibold text-slate-900 dark:text-white">{d.serial_number}</div>
                                        <div className="text-xs text-slate-500 dark:text-slate-400">
                                            {d.ip_address ?? 'unknown IP'} · {d.last_seen_human ?? 'never'}
                                        </div>
                                    </div>
                                    <button type="button" onClick={() => activate(d)} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                        Approve
                                    </button>
                                    <button type="button" onClick={() => setDeleting(d)} className="rounded-lg px-2 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">
                                        Reject
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Device cards */}
                {regular.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-900">
                        <Cpu className="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                        <h3 className="mt-3 font-semibold text-slate-900 dark:text-white">No devices yet</h3>
                        <p className="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                            Add the terminal's serial number, or just point the terminal at this server — it will appear above for approval.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {regular.map((d) => (
                            <DeviceCard
                                key={d.id}
                                device={d}
                                options={commandOptions}
                                onCommand={(t) => runCommand(d, t)}
                                onEdit={() => setEditing(d)}
                                onDelete={() => setDeleting(d)}
                                onActivate={() => activate(d)}
                            />
                        ))}
                    </div>
                )}

                <CommandsTable commands={commands} />
            </div>

            {editing && <DeviceForm device={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}

            {syncFor && (
                <SyncLogsModal
                    device={syncFor}
                    onClose={() => setSyncFor(null)}
                    onSubmit={(from, to) => {
                        send(syncFor, 'sync_logs', { from, to });
                        setSyncFor(null);
                    }}
                />
            )}

            {confirm && (
                <ConfirmModal
                    title={`${commandOptions.find((o) => o.value === confirm.type)?.label} — ${confirm.device.name}`}
                    message={COMMAND_META[confirm.type].confirm ?? ''}
                    danger={COMMAND_META[confirm.type].danger}
                    confirmLabel="Send to device"
                    onClose={() => setConfirm(null)}
                    onConfirm={() => {
                        send(confirm.device, confirm.type);
                        setConfirm(null);
                    }}
                />
            )}

            {deleting && (
                <ConfirmModal
                    title={`Remove ${deleting.name}?`}
                    message="The device is removed from the system. Attendance already received is kept. If the terminal is still pointed at this server it will re-appear as 'waiting for approval'."
                    danger
                    confirmLabel="Remove device"
                    onClose={() => setDeleting(null)}
                    onConfirm={() => {
                        router.delete(route('admin.hr.attendance.devices.destroy', deleting.id), { preserveScroll: true });
                        setDeleting(null);
                    }}
                />
            )}

            {toast && <Toast toast={toast} onClose={() => setToast(null)} />}
        </AdminLayout>
    );
}

/* ----------------------------------------------------------- setup card */

function SetupCard({ server }: { server: Props['server'] }) {
    const local = ['127.0.0.1', 'localhost', '::1'].includes(server.address);
    const rows: [string, string][] = [
        ['Server Mode', 'ADMS'],
        ['Enable Domain Name', 'OFF'],
        ['Server Address', server.address],
        ['Server Port', String(server.port)],
        ['Enable Proxy Server', 'OFF'],
        ['HTTPS', 'OFF'],
    ];

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:gap-8">
                <div className="lg:w-72">
                    <div className="flex items-center gap-2">
                        <span className="rounded-lg bg-teal-100 p-2 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300">
                            <Server className="h-5 w-5" />
                        </span>
                        <h2 className="font-semibold text-slate-900 dark:text-white">Terminal setup</h2>
                    </div>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        On the device: <b className="text-slate-700 dark:text-slate-200">Menu → COMM. → Cloud Server Setting</b>. The device then pushes every
                        punch here within seconds. Clock time zone: {server.timezone}.
                    </p>
                </div>
                <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3">
                    {rows.map(([k, v]) => (
                        <CopyField key={k} label={k} value={v} />
                    ))}
                </div>
            </div>
            {local && (
                <div className="flex items-start gap-2 border-t border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    You opened this page via {server.address}. On the device use this server's LAN IP (e.g. 192.168.10.x) — or set ZKTECO_SERVER_ADDRESS in .env.
                </div>
            )}
        </div>
    );
}

function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1200);
        } catch {
            /* clipboard unavailable on http:// — ignore */
        }
    };
    return (
        <button
            type="button"
            onClick={copy}
            className="group rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:border-teal-300 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-teal-500/40"
        >
            <div className="flex items-center justify-between text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                {label}
                {copied ? <Check className="h-3.5 w-3.5 text-emerald-500" /> : <Copy className="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100" />}
            </div>
            <div className="mt-0.5 font-mono text-sm font-semibold text-slate-900 dark:text-white">{value}</div>
        </button>
    );
}

/* ---------------------------------------------------------- device card */

function DeviceCard({
    device: d,
    options,
    onCommand,
    onEdit,
    onDelete,
    onActivate,
}: {
    device: Device;
    options: Props['commandOptions'];
    onCommand: (t: CommandType) => void;
    onEdit: () => void;
    onDelete: () => void;
    onActivate: () => void;
}) {
    const state = !d.is_active ? 'disabled' : d.online ? 'online' : 'offline';
    const stateStyle = {
        online: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
        offline: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
        disabled: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-500/20',
    }[state];

    return (
        <div className="flex flex-col rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-start justify-between gap-3 p-5">
                <div className="flex min-w-0 items-start gap-3">
                    <span
                        className={cx(
                            'flex h-12 w-12 shrink-0 items-center justify-center rounded-xl',
                            d.online
                                ? 'bg-gradient-to-br from-teal-500 to-emerald-600 text-white'
                                : 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500',
                        )}
                    >
                        <Fingerprint className="h-6 w-6" />
                    </span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="truncate text-base font-semibold text-slate-900 dark:text-white">{d.name}</h3>
                            <span className={cx('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', stateStyle)}>
                                {state === 'online' && <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />}
                                {state === 'online' ? 'Online' : state === 'offline' ? 'Offline' : 'Disabled'}
                            </span>
                        </div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-slate-500 dark:text-slate-400">
                            <span>{d.model ?? 'ZKTeco terminal'}</span>
                            {d.location && (
                                <span className="inline-flex items-center gap-1">
                                    <MapPin className="h-3.5 w-3.5" /> {d.location}
                                </span>
                            )}
                        </div>
                    </div>
                </div>
                <ActionMenu device={d} options={options} onCommand={onCommand} onEdit={onEdit} onDelete={onDelete} onActivate={onActivate} />
            </div>

            {d.protocol_warning && (
                <div className="mx-5 mb-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                    <AlertTriangle className="h-4 w-4 shrink-0" /> {d.protocol_warning}
                </div>
            )}

            <div className="grid grid-cols-3 border-y border-slate-100 dark:border-slate-800">
                <Metric label="Punches today" value={d.today_punches} highlight />
                <Metric label="Users / Faces" value={`${d.user_count ?? '–'} / ${d.face_count ?? '–'}`} />
                <Metric label="Logs on device" value={d.attlog_count ?? '–'} />
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-3">
                <Field label="Serial number" value={d.serial_number} mono />
                <Field label="IP address" value={d.ip_address} mono />
                <Field label="MAC" value={d.mac_address} mono />
                <Field label="Last seen" value={d.last_seen_human ?? 'Never'} />
                <Field label="Last punch" value={d.last_punch_at} />
                <Field label="Firmware" value={[d.firmware_version, d.push_version && `push ${d.push_version}`].filter(Boolean).join(' · ') || null} />
            </dl>

            <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                {d.is_active ? (
                    <>
                        <QuickButton onClick={() => onCommand('check')} icon={<Upload className="h-3.5 w-3.5" />}>
                            Upload new logs
                        </QuickButton>
                        <QuickButton onClick={() => onCommand('sync_logs')} icon={<Download className="h-3.5 w-3.5" />}>
                            Pull by date
                        </QuickButton>
                        <QuickButton onClick={() => onCommand('info')} icon={<RefreshCw className="h-3.5 w-3.5" />}>
                            Refresh info
                        </QuickButton>
                    </>
                ) : (
                    <QuickButton onClick={onActivate} icon={<CheckCircle2 className="h-3.5 w-3.5" />}>
                        Enable device
                    </QuickButton>
                )}
                {d.pending_commands > 0 && (
                    <span className="ml-auto inline-flex items-center gap-1 text-xs font-medium text-sky-600 dark:text-sky-400">
                        <Activity className="h-3.5 w-3.5 animate-pulse" /> {d.pending_commands} command(s) waiting for the device
                    </span>
                )}
            </div>
        </div>
    );
}

function ActionMenu({
    device,
    options,
    onCommand,
    onEdit,
    onDelete,
    onActivate,
}: {
    device: Device;
    options: Props['commandOptions'];
    onCommand: (t: CommandType) => void;
    onEdit: () => void;
    onDelete: () => void;
    onActivate: () => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const item = 'flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left text-sm transition hover:bg-slate-100 dark:hover:bg-slate-800';

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                aria-label="Device actions"
            >
                <MoreVertical className="h-5 w-5" />
            </button>
            {open && (
                <div className="absolute right-0 z-30 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    {device.is_active ? (
                        options.map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                className={cx(item, COMMAND_META[o.value].danger ? 'text-rose-600 dark:text-rose-400' : 'text-slate-700 dark:text-slate-200')}
                                onClick={() => {
                                    setOpen(false);
                                    onCommand(o.value);
                                }}
                            >
                                <span className="mt-0.5 opacity-70">{COMMAND_META[o.value].icon}</span>
                                <span>
                                    <span className="block font-medium">{o.label}</span>
                                    <span className="block text-xs text-slate-500 dark:text-slate-400">{COMMAND_META[o.value].help}</span>
                                </span>
                            </button>
                        ))
                    ) : (
                        <button type="button" className={cx(item, 'text-emerald-700 dark:text-emerald-400')} onClick={() => (setOpen(false), onActivate())}>
                            <CheckCircle2 className="mt-0.5 h-4 w-4" /> <span className="font-medium">Enable device</span>
                        </button>
                    )}
                    <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
                    <button type="button" className={cx(item, 'text-slate-700 dark:text-slate-200')} onClick={() => (setOpen(false), onEdit())}>
                        <Pencil className="mt-0.5 h-4 w-4 opacity-70" /> <span className="font-medium">Edit details</span>
                    </button>
                    <button type="button" className={cx(item, 'text-rose-600 dark:text-rose-400')} onClick={() => (setOpen(false), onDelete())}>
                        <Trash2 className="mt-0.5 h-4 w-4" /> <span className="font-medium">Remove device</span>
                    </button>
                </div>
            )}
        </div>
    );
}

/* -------------------------------------------------------- commands table */

function CommandsTable({ commands }: { commands: CommandRow[] }) {
    const style: Record<CommandRow['status'], string> = {
        pending: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        sent: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        failed: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        expired: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    };
    const label: Record<CommandRow['status'], string> = {
        pending: 'Waiting for device',
        sent: 'Sent',
        success: 'Done',
        failed: 'Failed',
        expired: 'No answer',
    };

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h2 className="font-semibold text-slate-900 dark:text-white">Recent device commands</h2>
                <span className="text-xs text-slate-500 dark:text-slate-400">Delivered on the device's next poll (~10 s)</span>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                            <th className="px-5 py-3">#</th>
                            <th className="px-5 py-3">Device</th>
                            <th className="px-5 py-3">Action</th>
                            <th className="px-5 py-3">Protocol command</th>
                            <th className="px-5 py-3">Status</th>
                            <th className="px-5 py-3">Requested</th>
                            <th className="px-5 py-3">Completed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {commands.map((c) => (
                            <tr key={c.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <td className="px-5 py-2.5 font-mono text-xs text-slate-400">{c.id}</td>
                                <td className="px-5 py-2.5 text-slate-700 dark:text-slate-200">{c.device ?? '—'}</td>
                                <td className="px-5 py-2.5 font-medium text-slate-900 dark:text-white">{c.label}</td>
                                <td className="max-w-xs truncate px-5 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400" title={c.command}>
                                    {c.command}
                                </td>
                                <td className="px-5 py-2.5">
                                    <span className={cx('rounded-full px-2.5 py-1 text-xs font-semibold', style[c.status])}>
                                        {label[c.status]}
                                        {c.status === 'failed' && c.return_code !== null && ` (${c.return_code})`}
                                    </span>
                                </td>
                                <td className="whitespace-nowrap px-5 py-2.5 text-slate-500 dark:text-slate-400">{c.created_human}</td>
                                <td className="whitespace-nowrap px-5 py-2.5 text-slate-500 dark:text-slate-400">{c.completed_at ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {commands.length === 0 && <div className="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">No commands sent yet.</div>}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- modals */

function DeviceForm({ device, onClose }: { device: Device | null; onClose: () => void }) {
    const form = useForm({
        serial_number: device?.serial_number ?? '',
        name: device?.name ?? '',
        location: device?.location ?? '',
        timezone: device?.timezone ?? '',
        notes: device?.notes ?? '',
        is_active: device?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (device) form.put(route('admin.hr.attendance.devices.update', device.id), opts);
        else form.post(route('admin.hr.attendance.devices.store'), opts);
    };

    return (
        <Modal title={device ? `Edit ${device.name}` : 'Add attendance device'} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Serial number"
                    value={form.data.serial_number}
                    onChange={(v) => form.setData('serial_number', v.toUpperCase())}
                    error={form.errors.serial_number}
                    placeholder="e.g. VGU56244900309"
                    hint="Menu → System Info → Device Info"
                    disabled={!!device}
                    mono
                    required
                />
                <Input label="Name" value={form.data.name} onChange={(v) => form.setData('name', v)} error={form.errors.name} placeholder="e.g. Main Entrance" required />
                <Input label="Location" value={form.data.location} onChange={(v) => form.setData('location', v)} error={form.errors.location} placeholder="e.g. Ground floor, reception" />
                <Input
                    label="Clock time zone (optional)"
                    value={form.data.timezone}
                    onChange={(v) => form.setData('timezone', v)}
                    error={form.errors.timezone}
                    placeholder="Default: Asia/Dhaka"
                    mono
                />
                <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-700">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                    />
                    <span className="text-sm">
                        <span className="font-medium text-slate-800 dark:text-slate-100">Active</span>
                        <span className="block text-xs text-slate-500 dark:text-slate-400">Inactive devices are refused; they keep their data until enabled.</span>
                    </span>
                </label>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : device ? 'Save changes' : 'Add device'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function SyncLogsModal({ device, onClose, onSubmit }: { device: Device; onClose: () => void; onSubmit: (from: string, to: string) => void }) {
    const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const today = ymd(new Date());
    const weekAgo = ymd(new Date(Date.now() - 6 * 86400000));
    const [from, setFrom] = useState(weekAgo);
    const [to, setTo] = useState(today);
    const invalid = !from || !to || from > to;
    const cls =
        'mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:[color-scheme:dark]';

    return (
        <Modal title={`Pull logs — ${device.name}`} onClose={onClose}>
            <div className="grid grid-cols-2 gap-3">
                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                    From
                    <input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className={cls} />
                </label>
                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                    To
                    <input type="date" value={to} max={today} onChange={(e) => setTo(e.target.value)} className={cls} />
                </label>
            </div>
            <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Already-received punches are ignored automatically. If this command shows <b>Failed (-1002)</b>, your firmware doesn't support date
                queries — use <b>Re-upload ALL logs</b> instead.
            </p>
            <div className="mt-6 flex justify-end gap-2">
                <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    Cancel
                </button>
                <button
                    type="button"
                    disabled={invalid}
                    onClick={() => onSubmit(from, to)}
                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-50"
                >
                    <Download className="h-4 w-4" /> Request logs
                </button>
            </div>
        </Modal>
    );
}

function ConfirmModal({
    title,
    message,
    confirmLabel,
    danger,
    onClose,
    onConfirm,
}: {
    title: string;
    message: string;
    confirmLabel: string;
    danger?: boolean;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Modal title={title} onClose={onClose}>
            <p className="text-sm leading-relaxed text-slate-600 dark:text-slate-300">{message}</p>
            <div className="mt-6 flex justify-end gap-2">
                <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    className={cx(
                        'rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm',
                        danger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-teal-600 hover:bg-teal-700',
                    )}
                >
                    {confirmLabel}
                </button>
            </div>
        </Modal>
    );
}

/* ------------------------------------------------------------ ui atoms */

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} />
            <div role="dialog" aria-modal="true" className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <div className="mb-4 flex items-center justify-between gap-4">
                    <h3 className="text-lg font-semibold text-slate-900 dark:text-white">{title}</h3>
                    <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Input({
    label,
    value,
    onChange,
    error,
    placeholder,
    hint,
    disabled,
    mono,
    required,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    placeholder?: string;
    hint?: string;
    disabled?: boolean;
    mono?: boolean;
    required?: boolean;
}) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                {label} {required && <span className="text-rose-500">*</span>}
            </span>
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                disabled={disabled}
                required={required}
                className={cx(
                    'mt-1 w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-800',
                    mono && 'font-mono',
                    error
                        ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20'
                        : 'border-slate-200 focus:border-teal-500 focus:ring-teal-500/20 dark:border-slate-700',
                )}
            />
            {error ? <span className="mt-1 block text-xs text-rose-600">{error}</span> : hint && <span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">{hint}</span>}
        </label>
    );
}

function Metric({ label, value, highlight }: { label: string; value: ReactNode; highlight?: boolean }) {
    return (
        <div className="px-5 py-3 [&:not(:first-child)]:border-l [&:not(:first-child)]:border-slate-100 dark:[&:not(:first-child)]:border-slate-800">
            <div className="text-[11px] font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">{label}</div>
            <div className={cx('mt-0.5 text-lg font-bold tabular-nums', highlight ? 'text-teal-600 dark:text-teal-400' : 'text-slate-900 dark:text-white')}>{value}</div>
        </div>
    );
}

function Field({ label, value, mono }: { label: string; value: string | null; mono?: boolean }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-slate-500 dark:text-slate-400">{label}</dt>
            <dd className={cx('truncate font-medium text-slate-800 dark:text-slate-200', mono && 'font-mono text-[13px]')} title={value ?? undefined}>
                {value ?? '—'}
            </dd>
        </div>
    );
}

function QuickButton({ onClick, icon, children }: { onClick: () => void; icon: ReactNode; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-teal-500/40 dark:hover:bg-teal-500/10 dark:hover:text-teal-300"
        >
            {icon}
            {children}
        </button>
    );
}

function Toast({ toast, onClose }: { toast: { type: 'success' | 'error'; text: string }; onClose: () => void }) {
    return (
        <div className="fixed bottom-6 right-6 z-50 max-w-sm">
            <div
                className={cx(
                    'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg',
                    toast.type === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950 dark:text-emerald-200'
                        : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-950 dark:text-rose-200',
                )}
            >
                <span className="flex-1">{toast.text}</span>
                <button type="button" onClick={onClose} className="opacity-60 hover:opacity-100" aria-label="Close">
                    <X className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}
