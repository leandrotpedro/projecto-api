<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use App\Models\User;

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
        $this->configureDefaults();
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        Gate::define('manage-store', fn (User $user): bool => $user->isAdmin() || $user->isManager());
        Gate::define('manage-staff', fn (User $user): bool => $user->isAdmin() || $user->isManager());
        Gate::define('manage-cash', fn (User $user): bool => $user->isAdmin() || $user->isManager() || $user->isCashier());
        Gate::define('manage-cash-session', fn (User $user): bool => $user->isAdmin() || $user->isManager() || $user->isCashier() || $user->isSeller());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
