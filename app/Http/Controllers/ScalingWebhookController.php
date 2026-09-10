<?php

namespace App\Http\Controllers;

use App\Contracts\MetricDispatcherInterface;
use App\Services\Scaling\Drivers\PrometheusMetricDispatcher;
use App\Services\Scaling\QueuePressureMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ScalingWebhookController extends Controller
{
    public function __construct(
        protected QueuePressureMonitor $monitor,
        protected MetricDispatcherInterface $dispatcher
    ) {}

    /**
     * Evaluate queue pressure and provide autoscaling recommendation for KEDA / AWS Auto Scaling.
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'queue' => ['sometimes', 'string'],
            'connection' => ['sometimes', 'nullable', 'string'],
            'current_workers' => ['sometimes', 'integer', 'min:1'],
        ]);

        $queue = $validated['queue'] ?? 'default';
        $connection = $validated['connection'] ?? null;
        $currentWorkers = (int) ($validated['current_workers'] ?? 4);

        $plan = $this->monitor->computeAutoscalingPlan($currentWorkers, $queue, $connection);

        return response()->json([
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
            'scaling' => $plan,
        ]);
    }

    /**
     * Prometheus Metric Scrape Endpoint.
     *
     * Returns OpenMetrics / Prometheus gauge exposition format.
     */
    public function metrics(): Response
    {
        if ($this->dispatcher instanceof PrometheusMetricDispatcher) {
            $content = $this->dispatcher->renderExposition();
        } else {
            // Fallback generation for Prometheus scraper
            $dispatcher = new PrometheusMetricDispatcher(
                namespace: (string) config('scaling.drivers.prometheus.namespace', 'laravel_queue')
            );

            $queues = config('scaling.queues', ['default', 'high']);
            foreach ($queues as $queue) {
                $evaluation = $this->monitor->evaluateQueuePressure($queue);
                $tags = ['queue' => $queue];
                $dispatcher->dispatch('queue_size', $evaluation['size'], $tags);
                $dispatcher->dispatch('wait_time_seconds', $evaluation['wait_time_seconds'], $tags);
                $dispatcher->dispatch('queue_pressure', $evaluation['pressure_score'], $tags);
            }

            $content = $dispatcher->renderExposition();
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * Real-time dashboard status overview of all configured queues.
     */
    public function status(): JsonResponse
    {
        $queues = config('scaling.queues', ['default', 'high']);
        $queueReports = [];

        foreach ($queues as $q) {
            $queueReports[$q] = [
                'pressure' => $this->monitor->evaluateQueuePressure($q),
                'scaling_plan' => $this->monitor->computeAutoscalingPlan(4, $q),
            ];
        }

        return response()->json([
            'architecture' => 'Laravel 13 Ultra-High-Throughput Blueprint',
            'timestamp' => now()->toIso8601String(),
            'queues' => $queueReports,
            'thresholds' => config('scaling.thresholds'),
        ]);
    }
}
