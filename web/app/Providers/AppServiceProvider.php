<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
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
        // Solo ID numerici; tutto il resto è 404 anziché un errore del database.
        foreach (['company', 'vatProfile', 'importBatch', 'invoice', 'document', 'vatRegistration', 'account', 'nonVatEntry', 'movement', 'allocation', 'vatAdjustment', 'f24Draft', 'vatCredit', 'vatCreditMovement', 'vatClaim', 'invoiceReprocessing', 'invoiceIntegration', 'xmlExport'] as $param) {
            Route::pattern($param, '[0-9]+');
        }
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
