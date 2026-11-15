import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Cpu,
    Download,
    Fingerprint,
    Link2,
    LogIn,
    LogOut,
    RefreshCw,
    Search,
    Timer,
    UserCheck,
    UserMinus,
    Users,
    X,
} from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';

// Ziggy's global route() helper.
declare function route(name: string, params?: Record<string, unknown> | number | string): string;

/* ------------------------------------------------------------------ types */

type Tab = 'daily' | 'punches' | 'unmapped';
type DailyStatus = 'present' | 'on_duty' | 'incomplete' | 'absent';

interface Filters {
    tab: Tab;
    date: string;
    from: string;
    to: string;
    status: 'all' | 'present' | 'on_duty' | 'incomplete' | 'late' | 'absent';
    search: string;
    device_id: number | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
    current_page: number;
    last_page: number;
}

interface DailyRow {
    employee_id: number;
    name: string;
    pin: string;
    first_in: string | null;
    last_out: string | null;
    last_out_next_day: boolean;
    worked_minutes: number;
    punch_count: number;
    shift_name: string | null;
    late_minutes: number;
    status: DailyStatus;
    status_label: string;
    source: string | null;
    remarks: string | null;
}

interface PunchRow {
    id: number;
    pin: string;
    employee_id: number | null;
    employee_name: string | null;
    device: string;
    date: string;
    time: string;
    duty_date: string | null;
    status: string;
    verify: string;
}

interface UnmappedRow {
    pin: string;
    device_name: string | null;
    punches: number;
    first_seen: string;
    last_seen: string;
}

interface Stats {
    total: number;
    present: number;
    on_duty: number;
    incomplete: number;
    late: number;
    absent: number;
    punches: number;
}

interface DeviceLite {
    id: number;
    name: string;
    online: boolean;
    last_seen_human: string | null;
}

interface Props {
    filters: Filters;
    timezone: string;
    employeeModelOk: boolean;
    stats: Stats | null;
    devices: DeviceLite[];
    unmappedCount: number;
    daily: Paginated<DailyRow> | null;
    punches: Paginated<PunchRow> | null;
    unmapped: UnmappedRow[] | null;
}

/* ---------------------------------------------------------------- helpers */

const INDEX_ROUTE = 'admin.hr.attendance.machine.index';

const shiftDate = (ymd: string, days: number) => {
    const d = new Date(`${ymd}T00:00:00`);
    d.setDate(d.getDate() + days);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

const todayIn = (tz: string) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

const prettyDate = (ymd: string) =>
    new Date(`${ymd}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });

const hm = (minutes: number) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;

const cleanLabel = (label: string) => label.replace('&laquo;', '‹').replace('&raquo;', '›').replace(/&[a-z]+;/g, '').trim();

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ');

const STATUS_STYLE: Record<DailyStatus, string> = {
    present: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
    on_duty: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
    incomplete: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
    absent: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-400 dark:ring-slate-400/20',
};

/* ------------------------------------------------------------- component */

export default function MachineAttendance({
    filters,
    timezone,
    employeeModelOk,
    stats,
    devices,
    unmappedCount,
    daily,
    punches,
    unmapped,
}: Props) {
    const flash = (usePage().props as { flash?: { success?: string; error?: string } }).flash;
    const [search, setSearch] = useState(filters.search);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [loading, setLoading] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const firstRender = useRef(true);
    const today = useMemo(() => todayIn(timezone), [timezone]);

    const visit = (changes: Partial<Filters>, opts: { replace?: boolean } = {}) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = { tab: next.tab };
        if (next.tab === 'daily') {
            query.date = next.date;
            if (next.status !== 'all') query.status = next.status;
        }
        if (next.tab === 'punches') {
            query.from = next.from;
            query.to = next.to;
            if (next.device_id) query.device_id = next.device_id;
        }
        if (next.search && next.tab !== 'unmapped') query.search = next.search;

        router.get(route(INDEX_ROUTE), query, {
            preserveState: true,
            preserveScroll: true,
            replace: opts.replace ?? false,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    // Debounced search.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const t = setTimeout(() => visit({ search }, { replace: true }), 400);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Live refresh (only when looking at today's data).
    const isLive = (filters.tab === 'daily' && filters.date === today) || (filters.tab === 'punches' && filters.to >= today);
    useEffect(() => {
        if (!autoRefresh || !isLive) return;
        const id = setInterval(() => {
            router.reload({ only: ['stats', 'daily', 'punches', 'devices', 'unmappedCount'] });
        }, 30000);
        return () => clearInterval(id);
    }, [autoRefresh, isLive]);

    // Flash -> toast.
    useEffect(() => {
        if (flash?.success) setToast({ type: 'success', text: flash.success });
        else if (flash?.error) setToast({ type: 'error', text: flash.error });
    }, [flash?.success, flash?.error]);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), 5000);
        return () => clearTimeout(t);
    }, [toast]);

    const onlineCount = devices.filter((d) => d.online).length;

    return (
        <AdminLayout>
            <Head title="Machine Attendance" />

            <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">
                            <Fingerprint className="h-4 w-4" /> HR · Attendance
                        </div>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Machine Attendance</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Punches pushed live by ZKTeco terminals · times shown in {timezone}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <DevicePills devices={devices} />
                        <button
                            type="button"
                            onClick={() => setAutoRefresh((v) => !v)}
                            className={cx(
                                'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                                autoRefresh && isLive
                                    ? 'border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-300'
                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800',
                            )}
                            title={isLive ? 'Refresh every 30 seconds' : 'Live refresh is only used for today'}
                        >
                            {loading ? (
                                <RefreshCw className="h-4 w-4 animate-spin" />
                            ) : autoRefresh && isLive ? (
                                <span className="relative flex h-2.5 w-2.5">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-teal-400 opacity-60" />
                                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-teal-500" />
                                </span>
                            ) : (
                                <RefreshCw className="h-4 w-4" />
                            )}
                            {autoRefresh && isLive ? 'Live' : 'Paused'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setExportOpen(true)}
                            disabled={!employeeModelOk}
                            className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            <Download className="h-4 w-4" /> Export
                        </button>
                        <Link
                            href={route('admin.hr.attendance.devices.index')}
                            className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                        >
                            <Cpu className="h-4 w-4" /> Devices
                            <span className="rounded bg-white/20 px-1.5 text-xs">
                                {onlineCount}/{devices.length}
                            </span>
                        </Link>
                    </div>
                </div>

                {!employeeModelOk && (
                    <Callout tone="danger" title="Employee model not found">
                        Check <code className="font-mono">config/zkteco.php → employee.model</code>. Punches are still being stored, but they
                        can't be linked to employees until this is fixed.
                    </Callout>
                )}

                {/* Tabs */}
                <div className="flex items-center gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
                    <TabButton active={filters.tab === 'daily'} onClick={() => visit({ tab: 'daily' })} icon={<CalendarDays className="h-4 w-4" />}>
                        Daily sheet
                    </TabButton>
                    <TabButton active={filters.tab === 'punches'} onClick={() => visit({ tab: 'punches' })} icon={<Clock3 className="h-4 w-4" />}>
                        Punch log
                    </TabButton>
                    <TabButton active={filters.tab === 'unmapped'} onClick={() => visit({ tab: 'unmapped' })} icon={<Link2 className="h-4 w-4" />}>
                        Unmapped PINs
                        {unmappedCount > 0 && (
                            <span className="ml-1 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                {unmappedCount}
                            </span>
                        )}
                    </TabButton>
                </div>

                {filters.tab === 'daily' && (
                    <DailyTab
                        filters={filters}
                        today={today}
                        stats={stats}
                        daily={daily}
                        search={search}
                        setSearch={setSearch}
                        visit={visit}
                        loading={loading}
                    />
                )}

                {filters.tab === 'punches' && (
                    <PunchesTab filters={filters} devices={devices} punches={punches} search={search} setSearch={setSearch} visit={visit} loading={loading} />
                )}

                {filters.tab === 'unmapped' && <UnmappedTab rows={unmapped ?? []} />}
            </div>

            {exportOpen && <ExportModal defaultFrom={filters.tab === 'daily' ? filters.date : filters.from} defaultTo={filters.tab === 'daily' ? filters.date : filters.to} onClose={() => setExportOpen(false)} />}

            {toast && (
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
                        <button type="button" onClick={() => setToast(null)} className="opacity-60 hover:opacity-100" aria-label="Close">
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

/* ------------------------------------------------------------- daily tab */

function DailyTab({
    filters,
    today,
    stats,
    daily,
    search,
    setSearch,
    visit,
    loading,
}: {
    filters: Filters;
    today: string;
    stats: Stats | null;
    daily: Paginated<DailyRow> | null;
    search: string;
    setSearch: (v: string) => void;
    visit: (c: Partial<Filters>) => void;
    loading: boolean;
}) {
    const s = stats ?? { total: 0, present: 0, on_duty: 0, incomplete: 0, late: 0, absent: 0, punches: 0 };
    const attended = s.present + s.on_duty + s.incomplete;
    const rate = s.total > 0 ? Math.round((attended / s.total) * 100) : 0;

    const chips: { key: Filters['status']; label: string; count?: number }[] = [
        { key: 'all', label: 'All', count: s.total },
        { key: 'present', label: 'Present', count: s.present },
        { key: 'on_duty', label: 'On duty', count: s.on_duty },
        { key: 'incomplete', label: 'Missing OUT', count: s.incomplete },
        { key: 'late', label: 'Late', count: s.late },
        { key: 'absent', label: 'No punch', count: s.absent },
    ];

    return (
        <div className="space-y-5">
            {/* Date bar */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <IconButton label="Previous day" onClick={() => visit({ date: shiftDate(filters.date, -1) })}>
                        <ChevronLeft className="h-4 w-4" />
                    </IconButton>
                    <input
                        type="date"
                        value={filters.date}
                        max={today}
                        onChange={(e) => e.target.value && visit({ date: e.target.value })}
                        className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:[color-scheme:dark]"
                    />
                    <IconButton label="Next day" onClick={() => visit({ date: shiftDate(filters.date, 1) })} disabled={filters.date >= today}>
                        <ChevronRight className="h-4 w-4" />
                    </IconButton>
                    {filters.date !== today && (
                        <button type="button" onClick={() => visit({ date: today })} className="text-sm font-medium text-teal-600 hover:underline dark:text-teal-400">
                            Today
                        </button>
                    )}
                </div>
                <p className="text-sm font-medium text-slate-600 dark:text-slate-300">{prettyDate(filters.date)}</p>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <StatCard icon={<Users className="h-5 w-5" />} label="Staff with device ID" value={s.total} tone="slate" />
                <StatCard icon={<UserCheck className="h-5 w-5" />} label="Attended" value={attended} hint={`${rate}% of staff`} tone="emerald" />
                <StatCard icon={<LogIn className="h-5 w-5" />} label="On duty now" value={s.on_duty} tone="sky" />
                <StatCard icon={<LogOut className="h-5 w-5" />} label="Missing OUT" value={s.incomplete} tone="amber" />
                <StatCard icon={<Timer className="h-5 w-5" />} label="Late" value={s.late} tone="orange" />
                <StatCard icon={<UserMinus className="h-5 w-5" />} label="No punch" value={s.absent} hint={`${s.punches} punches today`} tone="rose" />
            </div>

            {/* Table card */}
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex flex-wrap gap-1.5">
                        {chips.map((c) => (
                            <button
                                key={c.key}
                                type="button"
                                onClick={() => visit({ status: c.key })}
                                className={cx(
                                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition',
                                    filters.status === c.key
                                        ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700',
                                )}
                            >
                                {c.label}
                                <span className="tabular-nums opacity-70">{c.count}</span>
                            </button>
                        ))}
                    </div>
                    <SearchBox value={search} onChange={setSearch} placeholder="Search name or ID…" />
                </div>

                <div className={cx('overflow-x-auto transition-opacity', loading && 'opacity-60')}>
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                <th className="px-4 py-3">Employee</th>
                                <th className="px-4 py-3">Shift</th>
                                <th className="px-4 py-3">In</th>
                                <th className="px-4 py-3">Out</th>
                                <th className="px-4 py-3">Worked</th>
                                <th className="px-4 py-3 text-center">Punches</th>
                                <th className="px-4 py-3">Late</th>
                                <th className="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {(daily?.data ?? []).map((r) => (
                                <tr key={r.employee_id} className="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-3">
                                            <Avatar name={r.name} />
                                            <div className="min-w-0">
                                                <div className="truncate font-semibold text-slate-900 dark:text-slate-100">{r.name}</div>
                                                <div className="font-mono text-xs text-slate-500 dark:text-slate-400">ID {r.pin}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{r.shift_name ?? <Dash />}</td>
                                    <td className="px-4 py-3 font-medium tabular-nums text-slate-900 dark:text-slate-100">{r.first_in ?? <Dash />}</td>
                                    <td className="px-4 py-3 tabular-nums text-slate-900 dark:text-slate-100">
                                        {r.last_out ? (
                                            <span className="inline-flex items-center gap-1.5">
                                                {r.last_out}
                                                {r.last_out_next_day && (
                                                    <span className="rounded bg-indigo-50 px-1 text-[10px] font-bold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300" title="Next day">
                                                        +1
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            <Dash />
                                        )}
                                    </td>
                                    <td className="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{r.worked_minutes > 0 ? hm(r.worked_minutes) : <Dash />}</td>
                                    <td className="px-4 py-3 text-center tabular-nums text-slate-600 dark:text-slate-300">{r.punch_count || <Dash />}</td>
                                    <td className="px-4 py-3">
                                        {r.late_minutes > 0 ? (
                                            <span className="rounded-md bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">
                                                {r.late_minutes} min
                                            </span>
                                        ) : (
                                            <Dash />
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={cx('inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', STATUS_STYLE[r.status])}>
                                            {r.status_label}
                                        </span>
                                        {r.source === 'manual' && <span className="ml-2 text-[10px] font-semibold uppercase text-slate-400">manual</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {(daily?.data.length ?? 0) === 0 && <Empty text="No employees match these filters." />}
                </div>
                {daily && <Pagination page={daily} />}
            </div>
        </div>
    );
}

/* ----------------------------------------------------------- punches tab */

function PunchesTab({
    filters,
    devices,
    punches,
    search,
    setSearch,
    visit,
    loading,
}: {
    filters: Filters;
    devices: DeviceLite[];
    punches: Paginated<PunchRow> | null;
    search: string;
    setSearch: (v: string) => void;
    visit: (c: Partial<Filters>) => void;
    loading: boolean;
}) {
    const inputCls =
        'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:[color-scheme:dark]';

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 xl:flex-row xl:items-center xl:justify-between">
                <div className="flex flex-wrap items-center gap-2">
                    <label className="text-xs font-semibold uppercase tracking-wider text-slate-500">From</label>
                    <input type="date" value={filters.from} onChange={(e) => e.target.value && visit({ from: e.target.value })} className={inputCls} />
                    <label className="text-xs font-semibold uppercase tracking-wider text-slate-500">To</label>
                    <input type="date" value={filters.to} onChange={(e) => e.target.value && visit({ to: e.target.value })} className={inputCls} />
                    <select
                        value={filters.device_id ?? ''}
                        onChange={(e) => visit({ device_id: e.target.value ? Number(e.target.value) : null })}
                        className={inputCls}
                    >
                        <option value="">All devices</option>
                        {devices.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex items-center gap-3">
                    {punches && <span className="whitespace-nowrap text-sm text-slate-500 dark:text-slate-400">{punches.total} punches</span>}
                    <SearchBox value={search} onChange={setSearch} placeholder="Search name or PIN…" />
                </div>
            </div>

            <div className={cx('overflow-x-auto transition-opacity', loading && 'opacity-60')}>
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Time</th>
                            <th className="px-4 py-3">Employee</th>
                            <th className="px-4 py-3">PIN</th>
                            <th className="px-4 py-3">Device</th>
                            <th className="px-4 py-3">Type</th>
                            <th className="px-4 py-3">Verified by</th>
                            <th className="px-4 py-3">Duty date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {(punches?.data ?? []).map((p) => (
                            <tr key={p.id} className="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <td className="whitespace-nowrap px-4 py-2.5 text-slate-600 dark:text-slate-300">{p.date}</td>
                                <td className="whitespace-nowrap px-4 py-2.5 font-semibold tabular-nums text-slate-900 dark:text-slate-100">{p.time}</td>
                                <td className="px-4 py-2.5">
                                    {p.employee_id ? (
                                        <span className="font-medium text-slate-900 dark:text-slate-100">{p.employee_name ?? `#${p.employee_id}`}</span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                            <AlertTriangle className="h-3.5 w-3.5" /> Not linked
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{p.pin}</td>
                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">{p.device}</td>
                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">{p.status}</td>
                                <td className="px-4 py-2.5">
                                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{p.verify}</span>
                                </td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400">{p.duty_date ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {(punches?.data.length ?? 0) === 0 && <Empty text="No punches in this period." />}
            </div>
            {punches && <Pagination page={punches} />}
        </div>
    );
}

/* ---------------------------------------------------------- unmapped tab */

function UnmappedTab({ rows }: { rows: UnmappedRow[] }) {
    const [busy, setBusy] = useState(false);

    const relink = () =>
        router.post(route('admin.hr.attendance.machine.relink'), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <div className="space-y-4">
            <Callout tone="info" title="Why are these PINs unmapped?">
                The <b>User ID</b> typed on the terminal must match an employee's code exactly. Set the right code on the employee (or fix the
                ID on the device), then press <b>Re-link</b> — past punches are linked and their attendance is recalculated.
            </Callout>

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
                    <h3 className="font-semibold text-slate-900 dark:text-white">{rows.length} unmapped PIN(s)</h3>
                    <button
                        type="button"
                        onClick={relink}
                        disabled={busy}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
                    >
                        <Link2 className={cx('h-4 w-4', busy && 'animate-pulse')} /> Re-link now
                    </button>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                <th className="px-4 py-3">PIN on device</th>
                                <th className="px-4 py-3">Name on device</th>
                                <th className="px-4 py-3 text-center">Punches</th>
                                <th className="px-4 py-3">First seen</th>
                                <th className="px-4 py-3">Last seen</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {rows.map((r) => (
                                <tr key={r.pin} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                    <td className="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-slate-100">{r.pin}</td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{r.device_name ?? <Dash />}</td>
                                    <td className="px-4 py-3 text-center tabular-nums text-slate-600 dark:text-slate-300">{r.punches}</td>
                                    <td className="px-4 py-3 text-slate-500 dark:text-slate-400">{r.first_seen}</td>
                                    <td className="px-4 py-3 text-slate-500 dark:text-slate-400">{r.last_seen}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && <Empty text="Every punch is linked to an employee. 🎉" />}
                </div>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------- export modal */

function ExportModal({ defaultFrom, defaultTo, onClose }: { defaultFrom: string; defaultTo: string; onClose: () => void }) {
    const [from, setFrom] = useState(defaultFrom);
    const [to, setTo] = useState(defaultTo);
    const days = Math.round((new Date(`${to}T00:00:00`).getTime() - new Date(`${from}T00:00:00`).getTime()) / 86400000) + 1;
    const invalid = !from || !to || days < 1 || days > 63;
    const inputCls =
        'mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:[color-scheme:dark]';

    return (
        <Modal title="Export daily sheet (CSV)" onClose={onClose}>
            <div className="grid grid-cols-2 gap-3">
                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                    From
                    <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} />
                </label>
                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                    To
                    <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} />
                </label>
            </div>
            <p className={cx('mt-3 text-xs', invalid ? 'text-rose-600' : 'text-slate-500 dark:text-slate-400')}>
                {invalid ? 'Choose a valid range of up to 62 days.' : `${days} day(s) · one row per employee per day · opens in Excel`}
            </p>
            <div className="mt-6 flex justify-end gap-2">
                <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    Cancel
                </button>
                <a
                    href={invalid ? undefined : route('admin.hr.attendance.machine.export', { from, to })}
                    onClick={() => !invalid && setTimeout(onClose, 300)}
                    aria-disabled={invalid}
                    className={cx(
                        'inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700',
                        invalid && 'pointer-events-none opacity-50',
                    )}
                >
                    <Download className="h-4 w-4" /> Download
                </a>
            </div>
        </Modal>
    );
}

/* ------------------------------------------------------------ ui atoms */

function DevicePills({ devices }: { devices: DeviceLite[] }) {
    if (devices.length === 0) {
        return <span className="text-sm text-slate-500 dark:text-slate-400">No devices yet</span>;
    }
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {devices.slice(0, 3).map((d) => (
                <span
                    key={d.id}
                    title={d.last_seen_human ? `Last seen ${d.last_seen_human}` : 'Never connected'}
                    className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                >
                    <span className="relative flex h-2 w-2">
                        {d.online && <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />}
                        <span className={cx('relative inline-flex h-2 w-2 rounded-full', d.online ? 'bg-emerald-500' : 'bg-rose-500')} />
                    </span>
                    {d.name}
                </span>
            ))}
        </div>
    );
}

function TabButton({ active, onClick, icon, children }: { active: boolean; onClick: () => void; icon: ReactNode; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cx(
                'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition',
                active
                    ? 'bg-teal-600 text-white shadow-sm'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
            )}
        >
            {icon}
            {children}
        </button>
    );
}

const TONES = {
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    emerald: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
    sky: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300',
    amber: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
    orange: 'bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300',
    rose: 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300',
} as const;

function StatCard({ icon, label, value, hint, tone }: { icon: ReactNode; label: string; value: number; hint?: string; tone: keyof typeof TONES }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-slate-500 dark:text-slate-400">{label}</span>
                <span className={cx('rounded-lg p-1.5', TONES[tone])}>{icon}</span>
            </div>
            <div className="mt-2 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{value}</div>
            {hint && <div className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{hint}</div>}
        </div>
    );
}

function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
    return (
        <div className="relative w-full sm:w-72">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
        </div>
    );
}

function IconButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            disabled={disabled}
            className="rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
        >
            {children}
        </button>
    );
}

function Avatar({ name }: { name: string }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');
    return (
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-cyan-600 text-xs font-bold text-white">
            {initials || '?'}
        </span>
    );
}

function Callout({ tone, title, children }: { tone: 'info' | 'danger'; title: string; children: ReactNode }) {
    return (
        <div
            className={cx(
                'flex gap-3 rounded-xl border p-4 text-sm',
                tone === 'info'
                    ? 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100'
                    : 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-100',
            )}
        >
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <div className="font-semibold">{title}</div>
                <div className="mt-0.5 opacity-90">{children}</div>
            </div>
        </div>
    );
}

function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return page.total > 0 ? (
            <div className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                Showing {page.from}–{page.to} of {page.total}
            </div>
        ) : null;
    }
    return (
        <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row dark:border-slate-800">
            <span className="text-xs text-slate-500 dark:text-slate-400">
                Showing {page.from}–{page.to} of {page.total}
            </span>
            <div className="flex flex-wrap gap-1">
                {page.links.map((l, i) =>
                    l.url ? (
                        <Link
                            key={i}
                            href={l.url}
                            preserveScroll
                            preserveState
                            className={cx(
                                'min-w-[2rem] rounded-md px-2.5 py-1 text-center text-xs font-semibold transition',
                                l.active
                                    ? 'bg-teal-600 text-white'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                            )}
                        >
                            {cleanLabel(l.label)}
                        </Link>
                    ) : (
                        <span key={i} className="min-w-[2rem] px-2.5 py-1 text-center text-xs text-slate-400 dark:text-slate-600">
                            {cleanLabel(l.label)}
                        </span>
                    ),
                )}
            </div>
        </div>
    );
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} />
            <div role="dialog" aria-modal="true" className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <div className="mb-4 flex items-center justify-between">
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

const Dash = () => <span className="text-slate-300 dark:text-slate-600">—</span>;

const Empty = ({ text }: { text: string }) => <div className="px-4 py-14 text-center text-sm text-slate-500 dark:text-slate-400">{text}</div>;
