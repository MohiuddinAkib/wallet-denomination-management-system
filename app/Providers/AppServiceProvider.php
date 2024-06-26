<?php

namespace App\Providers;

use App\Domain\Currency\Contracts\CurrencyRepository as ContractsCurrencyRepository;
use App\Domain\Currency\Repositories\CurrencyRepository;
use App\Domain\Wallet\Policies\WalletPolicy;
use Arr;
use Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use RateLimiter;

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
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->app->bind(ContractsCurrencyRepository::class, CurrencyRepository::class);

        Gate::define('delete-wallet', [WalletPolicy::class, 'delete']);
        Gate::define('update-wallet', [WalletPolicy::class, 'update']);
        Gate::define('show-wallet', [WalletPolicy::class, 'show']);
        Gate::define('remove-wallet-denomination', [WalletPolicy::class, 'removeDenomination']);
        Gate::define('withdraw-wallet', [WalletPolicy::class, 'withdraw']);

        Request::macro('cacheKey', function () {
            /** @var Request $this */
            $url = $this->url();
            $queryParams = $this->query();

            $queryParams = Arr::sortRecursive($queryParams);

            $queryString = http_build_query($queryParams);

            $fullUrl = "{$url}?{$queryString}";

            $rememberKey = sha1($fullUrl);

            return $rememberKey;
        });

        Request::macro('fromStatefulFrontend', function () {
            /** @var Request $this */
            return EnsureFrontendRequestsAreStateful::fromFrontend($this);
        });
    }
}
