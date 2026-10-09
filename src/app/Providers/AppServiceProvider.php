<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Policies\ActivityPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InquiryPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Inquiry::class, InquiryPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}