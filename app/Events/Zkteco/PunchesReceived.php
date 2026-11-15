<?php

namespace App\Events\Zkteco;

use App\Models\AttendanceDevice;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after new punches from a device were stored. */
class PunchesReceived
{
    use Dispatchable;

    public function __construct(
        public readonly AttendanceDevice $device,
        public readonly int $count,
    ) {
    }
}
