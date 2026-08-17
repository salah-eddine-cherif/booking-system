<?php

namespace App\Providers;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every date in this application is an instant that gets converted between
        // zones repeatedly. Immutable dates make that safe: no method call can
        // quietly mutate a value another line still holds a reference to.
        Date::use(CarbonImmutable::class);

        // Deposits are charged to customers, who are not the staff `users`.
        Cashier::useCustomerModel(Customer::class);

        // The webhook route is declared in routes/api.php so it lives alongside
        // the rest of the API and can be renamed independently of the package.
        Cashier::ignoreRoutes();

        // Fail loudly in development on lazy loading, missing attributes and
        // silently discarded mass-assignment.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
