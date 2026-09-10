<?php

use App\Contracts\MetricDispatcherInterface;
use App\Services\Scaling\Drivers\LogMetricDispatcher;

test('benchmark:failed-jobs-scan executes and benchmarks full table scan vs composite index', function () {
    $this->artisan('benchmark:failed-jobs-scan', [
        '--count' => 100,
        '--iterations' => 2,
    ])
        ->expectsOutputToContain('SportMonks High-Throughput Benchmark')
        ->expectsOutputToContain('Composite Indexed Scan')
        ->expectsOutputToContain('Performance Multiplier')
        ->assertSuccessful();
});

test('benchmark:queue-pressure-simulation compares traditional CPU HPA vs Queue Pressure', function () {
    $this->artisan('benchmark:queue-pressure-simulation', [
        '--jobs' => 2500,
        '--worker-latency-ms' => 100,
        '--current-workers' => 2,
    ])
        ->expectsOutputToContain('The Autoscaling Flaw Simulation')
        ->expectsOutputToContain('Traditional CPU-based HPA')
        ->expectsOutputToContain('Queue Pressure Engine')
        ->assertSuccessful();
});

test('scaling:emit-queue-pressure dispatches metrics to active driver', function () {
    $mockDispatcher = new LogMetricDispatcher;
    $this->app->instance(MetricDispatcherInterface::class, $mockDispatcher);

    $this->artisan('scaling:emit-queue-pressure', [
        '--queue' => ['default', 'high'],
    ])
        ->expectsOutputToContain('Ultra-High-Throughput Queue Pressure Telemetry Engine')
        ->expectsOutputToContain('default')
        ->expectsOutputToContain('high')
        ->assertSuccessful();

    $dispatched = $mockDispatcher->getDispatchedMetrics();
    expect($dispatched)->not->toBeEmpty();
    expect(collect($dispatched)->pluck('metric'))->toContain('queue_size', 'wait_time_seconds', 'queue_pressure');
});
