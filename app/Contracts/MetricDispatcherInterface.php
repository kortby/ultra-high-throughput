<?php

namespace App\Contracts;

interface MetricDispatcherInterface
{
    /**
     * Dispatch a single metric telemetry point.
     *
     * @param  string  $metricName  Name of the metric (e.g. 'queue_pressure', 'wait_time_seconds')
     * @param  float|int  $value  Numerical metric value
     * @param  array<string, string>  $tags  Dimensional labels or tags (e.g. ['queue' => 'default'])
     */
    public function dispatch(string $metricName, float|int $value, array $tags = []): void;

    /**
     * Dispatch a batch of metric telemetry points.
     *
     * @param  array<int, array{metric: string, value: float|int, tags?: array<string, string>}>  $metrics
     */
    public function dispatchBatch(array $metrics): void;
}
