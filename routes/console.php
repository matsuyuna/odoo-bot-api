<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('odoo:contacts:pull --batch-size=500 --max-total=0')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('wati:contacts:push --limit=150')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('wati:contacts:pull --page-size=100 --max-pages=2')->everyTenMinutes()->withoutOverlapping();
