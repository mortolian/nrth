<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('estimates:expire')
    ->dailyAt('01:00')
    ->withoutOverlapping();

Schedule::command('invoices:generate-recurring')
    ->dailyAt('01:30')
    ->withoutOverlapping();

Schedule::command('takeouts:prune')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('nrth:backup-run')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('nrth:backup-rotate')
    ->dailyAt('03:30')
    ->withoutOverlapping();
