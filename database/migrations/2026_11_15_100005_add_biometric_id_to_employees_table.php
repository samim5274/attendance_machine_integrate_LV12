<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeric ID used as the "User ID" on the ZKTeco terminal (employee codes like
 * EMP-1001 contain letters, which the terminal does not accept).
 * Existing employees get the digits of their code (EMP-1001 -> 1001) when that
 * is unambiguous; anything else is left NULL to be filled in by HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_id', 32)->nullable()->unique()->after('employee_id');
        });

        $candidates = DB::table('employees')->whereNull('biometric_id')->pluck('employee_id', 'id')
            ->map(fn ($code) => ltrim(preg_replace('/\D+/', '', (string) $code), '0'))
            ->filter(fn ($digits) => $digits !== '' && strlen($digits) <= 9);

        $unique = $candidates->countBy()->filter(fn ($n) => $n === 1)->keys()->flip();

        foreach ($candidates as $id => $digits) {
            if (isset($unique[$digits])) {
                DB::table('employees')->where('id', $id)->update(['biometric_id' => $digits]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['biometric_id']);
            $table->dropColumn('biometric_id');
        });
    }
};
