<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hosting senza worker di coda permanente: il cronjob `php artisan schedule:run` (ogni minuto)
// elabora la coda del database (importazioni) e termina quando è vuota.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping();

// Fatture ricorrenti con emissione automatica: una volta al giorno al mattino.
Schedule::command('semplicon:recurring-run')->dailyAt('06:20')->withoutOverlapping();
