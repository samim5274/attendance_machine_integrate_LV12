<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Attendance devices / PIN mapping / machine attendance admin pages.
        Gate::define('manage-attendance-devices', function (User $user): bool {
            $allowed = array_map('strtolower', (array) config('zkteco.admin_emails', []));

            return $allowed === [] || in_array(strtolower((string) $user->email), $allowed, true);
        });

        // /iclock/* — per source IP; only meant to stop floods, not normal polling.
        RateLimiter::for('zkteco', fn (Request $request) => Limit::perMinute((int) config('zkteco.rate_limit_per_minute', 600))
            ->by('zk|'.$request->ip())
            ->response(fn () => response('TOO MANY REQUESTS', 429)->header('Content-Type', 'text/plain')));
    }
}
