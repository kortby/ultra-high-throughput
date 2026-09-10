<?php

namespace App\Services\Scaling\Drivers;

use App\Contracts\MetricDispatcherInterface;
use Illuminate\Support\Facades\Log;

class CloudWatchMetricDispatcher implements MetricDispatcherInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $metricBuffer = [];

    public function __construct(
        protected string $namespace = 'Laravel/HighThroughput',
        protected string $region = 'us-east-1'
    ) {}

    /**
     * Dispatch a single metric telemetry point.
     *
     * @param  array<string, string>  $tags
     */
    public function dispatch(string $metricName, float|int $value, array $tags = []): void
    {
        $dimensions = [];
        foreach ($tags as $name => $dimensionValue) {
            $dimensions[] = [
                'Name' => $name,
                'Value' => (string) $dimensionValue,
            ];
        }

        $metricDatum = [
            'MetricName' => $metricName,
            'Dimensions' => $dimensions,
            'Timestamp' => now()->toISOString(),
            'Value' => (float) $value,
            'Unit' => $this->resolveUnit($metricName),
            'StorageResolution' => 1, // 1-second high-resolution metric for high-throughput scaling
        ];

        $this->metricBuffer[] = $metricDatum;

        Log::debug('[CloudWatch Telemetry] Queued metric datum', [
            'namespace' => $this->namespace,
            'datum' => $metricDatum,
        ]);
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
     * Flush buffered metrics to CloudWatch PutMetricData API payload.
     *
     * @return array{Namespace: string, MetricData: array<int, array<string, mixed>>}
     */
    public function flushPayload(): array
    {
        $payload = [
            'Namespace' => $this->namespace,
            'MetricData' => $this->metricBuffer,
        ];

        $this->metricBuffer = [];

        return $payload;
    }

    /**
     * Resolve CloudWatch standard unit for metric.
     */
    protected function resolveUnit(string $metricName): string
    {
        return match (true) {
            str_contains($metricName, 'wait_time') || str_contains($metricName, 'duration') || str_contains($metricName, 'latency') => 'Seconds',
            str_contains($metricName, 'size') || str_contains($metricName, 'backlog') || str_contains($metricName, 'count') => 'Count',
            str_contains($metricName, 'pressure') || str_contains($metricName, 'score') => 'None',
            str_contains($metricName, 'cpu') || str_contains($metricName, 'utilization') => 'Percent',
            default => 'None',
        };
    }
}
