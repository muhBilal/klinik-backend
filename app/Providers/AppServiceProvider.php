<?php

namespace App\Providers;

use App\Enums\Izin;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PengaturanService;
use App\Support\CabangAktif;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // State per request (cabang aktif, cache pengaturan & audit)
        $this->app->scoped(CabangAktif::class);
        $this->app->scoped(PengaturanService::class);
        $this->app->scoped(AuditService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `$user->can('pasien.kelola')` / @can memakai izin RBAC.
        Gate::before(fn (User $user, string $ability) => Izin::tryFrom($ability) ? $user->punyaIzin($ability) : null);

        // Token tidak berlaku bila tidak dipakai selama `keamanan.idle_timeout_menit` (sesi idle di perangkat bersama)
        // atau pemiliknya dinonaktifkan. Masa berlaku absolut: config('sanctum.expiration').
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $menit = (int) app(PengaturanService::class)->get('keamanan.idle_timeout_menit');
            $terakhir = $token->last_used_at ?? $token->created_at;

            if ($menit > 0 && $terakhir->lt(now()->subMinutes($menit))) {
                return false;
            }

            return (bool) $token->tokenable?->is_active;
        });
    }
}
