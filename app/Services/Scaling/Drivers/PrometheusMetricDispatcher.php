<?php

namespace App\Services\Scaling\Drivers;

use App\Contracts\MetricDispatcherInterface;
use Illuminate\Support\Facades\Cache;

class PrometheusMetricDispatcher implements MetricDispatcherInterface
{
    /**
     * @var array<string, array{name: string, value: float|int, tags: array<string, string>, timestamp: int}>
     */
    protected array $inMemoryMetrics = [];

    public function __construct(
        protected string $namespace = 'laravel_queue',
        protected string $storageKey = 'metrics:prometheus:gauges'
    ) {}

    /**
     * Dispatch a single metric telemetry point.
     *
     * @param  array<string, string>  $tags
     */
    public function dispatch(string $metricName, float|int $value, array $tags = []): void
    {
        $fullName = "{$this->namespace}_{$metricName}";
        $labelKey = $this->buildLabelKey($tags);
        $compositeKey = "{$fullName}_{$labelKey}";

        $metricData = [
            'name' => $fullName,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => now()->getTimestamp(),
        ];

        $this->inMemoryMetrics[$compositeKey] = $metricData;

        // Persist to cache so Prometheus scraper endpoint can read it
        try {
            $existing = Cache::get($this->storageKey, []);
            $existing[$compositeKey] = $metricData;
            Cache::put($this->storageKey, $existing, now()->addMinutes(10));
        } catch (\Throwable) {
            // Gracefully ignore cache unavailability during CLI/isolated runs
        }
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
     * Render metrics in standard Prometheus text exposition format.
     */
    public function renderExposition(): string
    {
        $metrics = $this->inMemoryMetrics;
        try {
            $cached = Cache::get($this->storageKey, []);
            $metrics = array_merge($metrics, $cached);
        } catch (\Throwable) {
            // Use in-memory if cache is unavailable
        }

        if (empty($metrics)) {
            return "# No high-throughput queue metrics available\n";
        }

        $lines = [];
        $lines[] = '# HELP laravel_queue_metrics High-throughput queue pressure and wait-time telemetry';
        $lines[] = '# TYPE laravel_queue_metrics gauge';

        foreach ($metrics as $m) {
            $tagStr = '';
            if (! empty($m['tags'])) {
                $formattedTags = [];
                foreach ($m['tags'] as $k => $v) {
                    $formattedTags[] = sprintf('%s="%s"', $k, addcslashes($v, '"\\'));
                }
                $tagStr = '{'.implode(',', $formattedTags).'}';
            }

            $lines[] = sprintf('%s%s %s %d', $m['name'], $tagStr, $m['value'], $m['timestamp'] * 1000);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Helper to build a unique hash key for dimensional tags.
     *
     * @param  array<string, string>  $tags
     */
    protected function buildLabelKey(array $tags): string
    {
        ksort($tags);

        return md5(json_encode($tags) ?: '');
    }
}
