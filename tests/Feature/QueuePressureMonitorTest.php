<?php

use App\Services\Scaling\Drivers\CloudWatchMetricDispatcher;
use App\Services\Scaling\Drivers\LogMetricDispatcher;
use App\Services\Scaling\Drivers\PrometheusMetricDispatcher;
use App\Services\Scaling\QueuePressureMonitor;
use Illuminate\Support\Facades\DB;

test('performance indexes exist on failed_jobs and jobs tables', function () {
    // Check failed_jobs table indexes
    $failedJobsIndexes = collect(DB::select("PRAGMA index_list('failed_jobs')"))->pluck('name')->toArray();
    expect($failedJobsIndexes)->toContain('failed_jobs_queue_failed_at_index');
    expect($failedJobsIndexes)->toContain('failed_jobs_failed_at_index');

    // Check jobs table indexes
    $jobsIndexes = collect(DB::select("PRAGMA index_list('jobs')"))->pluck('name')->toArray();
    expect($jobsIndexes)->toContain('jobs_queue_reserved_at_index');
    expect($jobsIndexes)->toContain('jobs_queue_available_at_index');
});

test('queue pressure monitor evaluates backlog size and wait time correctly', function () {
    $dispatcher = new LogMetricDispatcher;
    $monitor = new QueuePressureMonitor($dispatcher);

    // Initial empty state
    expect($monitor->getQueueSize('default', 'database'))->toBe(0);
    expect($monitor->getWaitTimeSeconds('default', 'database'))->toBe(0.0);

    // Insert synthetic jobs in jobs table
    $now = now()->getTimestamp();
    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 60, // 60 seconds delayed
            'created_at' => $now - 60,
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 20,
            'created_at' => $now - 20,
        ],
        [
            'queue' => 'high',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 5,
            'created_at' => $now - 5,
        ],
    ]);

    expect($monitor->getQueueSize('default', 'database'))->toBe(2);
    expect($monitor->getWaitTimeSeconds('default', 'database'))->toBeGreaterThanOrEqual(59.0);

    $evaluation = $monitor->evaluateQueuePressure('default', 'database');
    expect($evaluation['size'])->toBe(2);
    expect($evaluation['wait_time_seconds'])->toBeGreaterThanOrEqual(59.0);
    expect($evaluation['pressure_score'])->toBeGreaterThan(100.0);
    expect($evaluation['status'])->toBeIn(['HIGH_PRESSURE', 'CRITICAL_CONGESTION']);
});

test('autoscaling engine triggers scale-up when pressure score exceeds threshold', function () {
    $dispatcher = new LogMetricDispatcher;
    $monitor = new QueuePressureMonitor($dispatcher);

    $now = now()->getTimestamp();
    // Populate heavy delayed queue
    for ($i = 0; $i < 200; $i++) {
        DB::table('jobs')->insert([
            'queue' => 'webhooks',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 120, // 120s wait time
            'created_at' => $now - 120,
        ]);
    }

    $plan = $monitor->computeAutoscalingPlan(currentWorkers: 2, queue: 'webhooks', connection: 'database');

    expect($plan['scaling_action'])->toBe('SCALE_UP');
    expect($plan['desired_workers'])->toBeGreaterThan(2);
    expect($plan['reason'])->toContain('exceeds threshold');
});

test('metric dispatcher drivers operate correctly', function () {
    // 1. Log Driver
    $logDriver = new LogMetricDispatcher;
    $logDriver->dispatch('test_metric', 42.5, ['queue' => 'default']);
    expect($logDriver->getDispatchedMetrics())->toHaveCount(1);
    expect($logDriver->getDispatchedMetrics()[0]['metric'])->toBe('test_metric');

    // 2. Prometheus Driver
    $promDriver = new PrometheusMetricDispatcher(namespace: 'test_queue');
    $promDriver->dispatch('wait_time_seconds', 18.4, ['queue' => 'webhooks']);
    $exposition = $promDriver->renderExposition();
    expect($exposition)->toContain('test_queue_wait_time_seconds{queue="webhooks"} 18.4');

    // 3. CloudWatch Driver
    $cwDriver = new CloudWatchMetricDispatcher(namespace: 'Laravel/Test');
    $cwDriver->dispatch('queue_pressure', 140.0, ['queue' => 'high']);
    $payload = $cwDriver->flushPayload();
    expect($payload['Namespace'])->toBe('Laravel/Test');
    expect($payload['MetricData'])->toHaveCount(1);
    expect($payload['MetricData'][0]['MetricName'])->toBe('queue_pressure');
    expect($payload['MetricData'][0]['Value'])->toBe(140.0);
});

test('scaling webhook endpoints provide KEDA and Prometheus responses', function () {
    // Test /api/scaling/status
    $statusResponse = $this->getJson('/api/scaling/status');
    $statusResponse->assertOk()
        ->assertJsonStructure([
            'architecture',
            'timestamp',
            'queues',
            'thresholds',
        ]);

    // Test /api/scaling/evaluate
    $evaluateResponse = $this->postJson('/api/scaling/evaluate', [
        'queue' => 'default',
        'current_workers' => 4,
    ]);
    $evaluateResponse->assertOk()
        ->assertJsonStructure([
            'status',
            'timestamp',
            'scaling' => [
                'current_workers',
                'desired_workers',
                'scaling_action',
                'reason',
                'pressure_metrics',
            ],
        ]);

    // Test /api/scaling/metrics (Prometheus scrape endpoint)
    $metricsResponse = $this->get('/api/scaling/metrics');
    $metricsResponse->assertOk();
    expect($metricsResponse->headers->get('Content-Type'))->toContain('text/plain');
});

test('high throughput database configuration has read-write replica and PDO options', function () {
    $mysqlConfig = config('database.connections.mysql');
    expect($mysqlConfig)->toHaveKeys(['read', 'write', 'sticky']);
    expect($mysqlConfig['sticky'])->toBeTrue();

    $planetscaleConfig = config('database.connections.planetscale');
    expect($planetscaleConfig)->toBeArray();
    expect($planetscaleConfig['sticky'])->toBeTrue();
});
