<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('odoo:contacts:pull --limit=300')->everyFiveMinutes();
Schedule::command('wati:contacts:push --limit=150')->everyFiveMinutes()->withoutOverlapping();
