<?php

namespace App\Providers;

use App\Contracts\MetricDispatcherInterface;
use App\Services\Scaling\Drivers\CloudWatchMetricDispatcher;
use App\Services\Scaling\Drivers\LogMetricDispatcher;
use App\Services\Scaling\Drivers\PrometheusMetricDispatcher;
use App\Services\Scaling\QueuePressureMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetricDispatcherInterface::class, function ($app) {
            $driver = config('scaling.default_driver', 'log');

            return match ($driver) {
                'prometheus' => new PrometheusMetricDispatcher(
                    namespace: config('scaling.drivers.prometheus.namespace', 'laravel_queue'),
                    storageKey: config('scaling.drivers.prometheus.storage_key', 'metrics:prometheus:gauges')
                ),
                'cloudwatch' => new CloudWatchMetricDispatcher(
                    namespace: config('scaling.drivers.cloudwatch.namespace', 'Laravel/HighThroughput'),
                    region: config('scaling.drivers.cloudwatch.region', 'us-east-1')
                ),
                default => new LogMetricDispatcher,
            };
        });

        $this->app->singleton(QueuePressureMonitor::class, function ($app) {
            return new QueuePressureMonitor(
                $app->make(MetricDispatcherInterface::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
