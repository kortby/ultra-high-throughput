<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Continuously emit queue pressure metrics for HPA / CloudWatch / Prometheus autoscaling
Schedule::command('scaling:emit-queue-pressure')->everyMinute();
