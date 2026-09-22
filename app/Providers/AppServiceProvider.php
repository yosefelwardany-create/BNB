<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tenancy and auditing are request-lifetime singletons: everything in
        // the application resolves the *same* instance so the bound tenant and
        // the correlation id are consistent.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(AccessControl::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureMorphMap();
        $this->configureGate();
        $this->configureUrls();
        $this->configureAuthNotifications();
        $this->configureFactories();
    }

    /**
     * Models live under app/Domain/<Domain>/Models, which Laravel's default
     * guesser would map to a nested factory namespace. Factories are kept flat
     * in database/factories, so the class basename is what resolves them.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    /**
     * Point the built-in auth emails at the right places.
     *
     * Verification links must resolve against the prefixed API route name, and
     * password reset links belong on the front end, not the API host.
     */
    private function configureAuthNotifications(): void
    {
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'api.v1.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            );
        });
    }

    private function configureModels(): void
    {
        // Fail loudly in development when a relationship was not eager loaded
        // or a non-existent attribute is read. Both are silent performance and
        // correctness bugs in production otherwise.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Model::unguard(false);
    }

    /**
     * Stable morph aliases.
     *
     * Polymorphic columns store these short names instead of fully-qualified
     * class names, so classes can be moved between namespaces without a data
     * migration — which matters because domains here are designed to be
     * extractable into services later.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap(config('morph-map'));
    }

    private function configureGate(): void
    {
        // Permission names from the registry resolve straight through the
        // access-control service. Anything else (model policies) falls through
        // to the normal Gate resolution by returning null.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->isPlatformAdmin()) {
                return true;
            }

            if (! PermissionRegistry::exists($ability)) {
                return null;
            }

            return app(AccessControl::class)->allows($user, $ability);
        });
    }

    private function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
