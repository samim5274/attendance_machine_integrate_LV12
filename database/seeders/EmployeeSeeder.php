<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Employees enrolled on the SenseFace 3A (serial VGU6244900309), taken from its
 * user list. The terminal's "User ID" (PIN) is saved as both employee_id and
 * biometric_id (ZKTECO_EMPLOYEE_PIN_COLUMN), so every punch links to the
 * employee automatically — no PIN mapping needed for these people.
 *
 * Safe to run again: a row is matched on biometric_id or employee_id and updated.
 *   php artisan db:seed --class=EmployeeSeeder
 */
class EmployeeSeeder extends Seeder
{
    /** PIN (User ID on the terminal) => name, as enrolled on the terminal. */
    private const DEVICE_USERS = [
        '2024001' => 'F.A.N. Morshed',          // terminal admin
        '2024003' => 'Dr. Mousumi Saha',
        '2024005' => 'Taharin Afroj',
        '2024011' => 'Dr. Fahamida Hasan',
        '2024013' => 'Md. Shariful Islam Emon', // terminal admin
        '2024023' => 'Monisha Rani Datta',
        '2025032' => 'Support Staff Male',
        '2025033' => 'Nijhum',
        '2025034' => 'Atia',
        '2025039' => 'Mst. Umme Tazin Jannat',
        '2025040' => 'Dr. Shahara Jannat Banni',
        '2025042' => 'Zeeshan',
        '2025044' => 'MOHAMMAD EAKUB ALI',
        '2025046' => 'Jennat Akter Bonna',
        '2025049' => 'Fatema Chowdhury',
        '2026003' => 'Salma Akter',
        '2026004' => 'Ashika Ashrafi',
        '2026005' => 'Md. Rakibul Islam Shuvo',
        '2026008' => 'Sadia Akter',
        '2026013' => 'Jaedun Nesa Urmy',
        '2026014' => 'Shamim Hossain',
        '2026016' => 'Rabeya Akter',
        '2026018' => 'Md. Siam Hossen',
        '2026019' => 'Dr. Md. Mahmudul Hasan',
        '2026020' => 'Shakil Hossain',
        '2026021' => 'Maria Akter Bristy',
        '2026022' => 'Jubaida Zahid Roza',
        '2026023' => 'Anzoman Ara',
        '2026024' => 'Jarin Akhter',
        '2026025' => 'Shima Begum',
        '9' => 'roni',                          // looks like a test user — delete this line if not an employee
    ];

    public function run(): void
    {
        $table = (new Employee())->getTable();
        $placeholders = $this->placeholdersForRequiredColumns($table, ['employee_id', 'biometric_id', 'name', 'status']);

        foreach ($placeholders as $column => $value) {
            $shown = $column === 'email' ? '<PIN>@staff.invalid' : var_export($value, true);
            $this->command?->warn("{$table}.{$column} is required: new rows get {$shown}. Fill in the real value later.");
        }

        $created = 0;
        $updated = 0;

        foreach (self::DEVICE_USERS as $pin => $name) {
            $pin = (string) $pin;
            $name = preg_replace('/\s+/u', ' ', trim($name));

            $employee = Employee::query()
                ->where('biometric_id', $pin)
                ->orWhere('employee_id', $pin)
                ->first();

            if ($employee !== null) {
                // Existing row: keep its employee code and status, just align name + PIN.
                $employee->forceFill(['name' => $name, 'biometric_id' => $pin])->save();
                $updated++;
                continue;
            }

            $values = ['employee_id' => $pin, 'biometric_id' => $pin, 'name' => $name, 'status' => 'active'];
            foreach ($placeholders as $column => $value) {
                $values[$column] = $column === 'email' ? "{$pin}@staff.invalid" : $value;
            }

            // forceFill: biometric_id is often not in Employee::$fillable.
            (new Employee())->forceFill($values)->save();
            $created++;
        }

        $this->command?->info("Employees from the terminal: {$created} created, {$updated} updated.");
    }

    /**
     * NOT NULL columns without a default that this seeder has no data for (email,
     * joining_date … depending on your migration). They get a neutral placeholder
     * so the seed works with any employees table; nullable columns stay empty.
     *
     * @param  list<string>  $provided
     * @return array<string, mixed> column => placeholder
     */
    private function placeholdersForRequiredColumns(string $table, array $provided): array
    {
        $skip = [...$provided, 'id', 'created_at', 'updated_at', 'deleted_at'];
        $numeric = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'];
        $out = [];

        foreach (Schema::getColumns($table) as $column) {
            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment'] || in_array($column['name'], $skip, true)) {
                continue;
            }

            $type = strtolower((string) $column['type_name']);

            if ($type === 'enum' && preg_match_all("/'((?:[^']|'')*)'/", (string) $column['type'], $m)) {
                // A neutral choice ("other", "unknown" …) if the enum has one, else its first value.
                $values = array_map(fn ($v) => str_replace("''", "'", $v), $m[1]);
                $neutral = array_values(array_filter($values, fn ($v) => in_array(strtolower($v), ['other', 'others', 'unknown', 'not_specified', 'n/a', 'na', 'none'], true)));
                $out[$column['name']] = $neutral[0] ?? $values[0];
            } elseif ($type === 'date') {
                $out[$column['name']] = now()->toDateString();
            } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                $out[$column['name']] = now()->toDateTimeString();
            } elseif (in_array($type, $numeric, true)) {
                $out[$column['name']] = 0;
            } else {
                $out[$column['name']] = '';
            }
        }

        return $out;
    }
}