<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Metric Dispatcher Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "log", "prometheus", "cloudwatch", "null"
    |
    */

    'default_driver' => env('SCALING_METRIC_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Monitored Queues
    |--------------------------------------------------------------------------
    |
    | Define the queue connections and queues to track for queue pressure.
    |
    */

    'queues' => [
        'default',
        'high',
        'webhooks',
        'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Pressure Evaluation Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds used by the Autoscaling engine (KEDA / AWS HPA / Webhooks).
    | Traditional CPU-based autoscaling fails for I/O-bound workers.
    | Queue Pressure combines backlog size and estimated wait time (seconds).
    |
    */

    'thresholds' => [
        // SLA target wait time in seconds before triggering scale up
        'target_wait_time_seconds' => (int) env('SCALING_TARGET_WAIT_TIME', 15),

        // SLA backlog depth per worker replica
        'target_backlog_per_worker' => (int) env('SCALING_TARGET_BACKLOG_PER_WORKER', 50),

        // Weighting factors for composite pressure formula
        'weight_wait_time' => 0.65,
        'weight_backlog' => 0.35,

        // Pressure score threshold (> 100 triggers scale up)
        'pressure_scale_up_threshold' => 100.0,
        'pressure_scale_down_threshold' => 30.0,

        // Minimum and maximum worker replica limits
        'min_workers' => (int) env('SCALING_MIN_WORKERS', 2),
        'max_workers' => (int) env('SCALING_MAX_WORKERS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver Configurations
    |--------------------------------------------------------------------------
    */

    'drivers' => [
        'cloudwatch' => [
            'namespace' => env('CLOUDWATCH_METRIC_NAMESPACE', 'Laravel/HighThroughput'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'prometheus' => [
            'namespace' => env('PROMETHEUS_METRIC_NAMESPACE', 'laravel_queue'),
            'storage_key' => 'metrics:prometheus:gauges',
        ],
    ],

];
