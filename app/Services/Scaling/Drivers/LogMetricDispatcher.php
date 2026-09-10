<?php

namespace App\Services\Scaling\Drivers;

use App\Contracts\MetricDispatcherInterface;
use Illuminate\Support\Facades\Log;

class LogMetricDispatcher implements MetricDispatcherInterface
{
    /**
     * @var array<int, array{metric: string, value: float|int, tags: array<string, string>, timestamp: int}>
     */
    protected array $dispatched = [];

    /**
     * Dispatch a single metric telemetry point.
     *
     * @param  array<string, string>  $tags
     */
    public function dispatch(string $metricName, float|int $value, array $tags = []): void
    {
        $payload = [
            'metric' => $metricName,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => now()->getTimestamp(),
        ];

        $this->dispatched[] = $payload;

        Log::info('[Scaling Telemetry] Metric emitted', $payload);
    }

    /**
     * Dispatch a batch of metric telemetry points.
     *
     * @param  array<int, array{metric: string, value: float|int, tags?: array<string, string>}>  $metrics
     */
    public function dispatchBatch(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->dispatch($metric['metric'], $metric['value'], $metric['tags'] ?? []);
        }
    }

    /**
     * Retrieve collected metrics in memory (useful for testing and CLI verification).
     *
     * @return array<int, array{metric: string, value: float|int, tags: array<string, string>, timestamp: int}>
     */
    public function getDispatchedMetrics(): array
    {
        return $this->dispatched;
    }
}
