<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ventana madrugada progresiva para evitar picos en Odoo/WATI:
// 1) pull Odoo -> cola local (01:00-02:25)
// 2) push cola -> WATI (01:30-03:50)
// 3) pull WATI -> Odoo (04:00-04:50)
// 4) BCV (05:00)
Schedule::command('odoo:contacts:pull --batch-size=500 --max-total=0')
    ->cron('*/5 1-2 * * *')
    ->withoutOverlapping();

Schedule::command('wati:contacts:push --limit=150')
    ->cron('30-59/10 1 * * *')
    ->withoutOverlapping();

Schedule::command('wati:contacts:push --limit=150')
    ->cron('*/10 2-3 * * *')
    ->withoutOverlapping();

Schedule::command('wati:contacts:pull --page-size=100 --max-pages=2')
    ->cron('*/10 4 * * *')
    ->withoutOverlapping();

Schedule::command('bcv:rates:sync')
    ->cron((string) env('BCV_SYNC_CRON', '0 5 * * *'))
    ->withoutOverlapping();
