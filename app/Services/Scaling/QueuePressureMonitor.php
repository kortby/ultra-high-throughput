<?php

namespace App\Services\Scaling;

use App\Contracts\MetricDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class QueuePressureMonitor
{
    public function __construct(
        protected MetricDispatcherInterface $dispatcher
    ) {}

    /**
     * Inspect queue backlog size (number of unhandled/pending jobs).
     */
    public function getQueueSize(string $queue = 'default', ?string $connection = null): int
    {
        $connection = $connection ?? config('queue.default', 'database');

        try {
            if ($connection === 'redis') {
                return (int) Redis::connection()->llen("queues:{$queue}");
            }

            if ($connection === 'database') {
                return (int) DB::table('jobs')
                    ->where('queue', $queue)
                    ->whereNull('reserved_at')
                    ->count();
            }

            return (int) Queue::connection($connection)->size($queue);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Calculate queue wait time in seconds (delay of oldest unhandled job).
     */
    public function getWaitTimeSeconds(string $queue = 'default', ?string $connection = null): float
    {
        $connection = $connection ?? config('queue.default', 'database');
        $currentTime = now()->getTimestamp();

        try {
            if ($connection === 'redis') {
                // Redis Horizon/Queue structure: inspect head of list or delayed zset
                $oldestPayload = Redis::connection()->lindex("queues:{$queue}", -1);
                if ($oldestPayload) {
                    $decoded = json_decode((string) $oldestPayload, true);
                    $pushedAt = $decoded['pushed_at'] ?? $decoded['createdAt'] ?? $currentTime;

                    return max(0.0, round($currentTime - (float) $pushedAt, 2));
                }

                return 0.0;
            }

            if ($connection === 'database') {
                $oldestJob = DB::table('jobs')
                    ->where('queue', $queue)
                    ->whereNull('reserved_at')
                    ->orderBy('available_at', 'asc')
                    ->first(['available_at', 'created_at']);

                if ($oldestJob) {
                    $jobAvailableAt = (int) ($oldestJob->available_at ?? $oldestJob->created_at);

                    return max(0.0, round((float) ($currentTime - $jobAvailableAt), 2));
                }

                return 0.0;
            }
        } catch (\Throwable) {
            return 0.0;
        }

        return 0.0;
    }

    /**
     * Compute composite Queue Pressure Score.
     *
     * Pressure Score combines wait time latency and backlog depth:
     * - A score of 100.0 represents 100% of target SLA capacity.
     * - A score > 100.0 signifies SLA violation and requires immediate worker scale out.
     *
     * @return array{
     *     queue: string,
     *     size: int,
     *     wait_time_seconds: float,
     *     pressure_score: float,
     *     status: string
     * }
     */
    public function evaluateQueuePressure(string $queue = 'default', ?string $connection = null): array
    {
        $size = $this->getQueueSize($queue, $connection);
        $waitTime = $this->getWaitTimeSeconds($queue, $connection);

        $targetWaitTime = (float) config('scaling.thresholds.target_wait_time_seconds', 15);
        $targetBacklog = (float) config('scaling.thresholds.target_backlog_per_worker', 50);
        $weightWait = (float) config('scaling.thresholds.weight_wait_time', 0.65);
        $weightBacklog = (float) config('scaling.thresholds.weight_backlog', 0.35);

        // Normalize metrics against SLA thresholds (100 = threshold reached)
        $normalizedWait = ($waitTime / max(1.0, $targetWaitTime)) * 100.0;
        $normalizedBacklog = ($size / max(1.0, $targetBacklog)) * 100.0;

        $pressureScore = round(($weightWait * $normalizedWait) + ($weightBacklog * $normalizedBacklog), 2);

        $status = match (true) {
            $pressureScore >= 150.0 => 'CRITICAL_CONGESTION',
            $pressureScore >= 100.0 => 'HIGH_PRESSURE',
            $pressureScore >= 60.0 => 'MODERATE_LOAD',
            default => 'HEALTHY',
        };

        return [
            'queue' => $queue,
            'size' => $size,
            'wait_time_seconds' => $waitTime,
            'pressure_score' => $pressureScore,
            'status' => $status,
        ];
    }

    /**
     * Evaluate Autoscaling recommendation based on Queue Pressure vs CPU.
     *
     * Demonstrates why traditional CPU-based autoscaling fails:
     * When workers are I/O bound (waiting on database locks or 3rd party APIs),
     * CPU stays low (<10%), causing HPA to scale workers down or do nothing,
     * causing queue backlog to explode.
     *
     * @return array{
     *     current_workers: int,
     *     desired_workers: int,
     *     scaling_action: string,
     *     reason: string,
     *     pressure_metrics: array<string, mixed>
     * }
     */
    public function computeAutoscalingPlan(
        int $currentWorkers = 4,
        string $queue = 'default',
        ?string $connection = null
    ): array {
        $metrics = $this->evaluateQueuePressure($queue, $connection);

        $minWorkers = (int) config('scaling.thresholds.min_workers', 2);
        $maxWorkers = (int) config('scaling.thresholds.max_workers', 50);
        $targetBacklogPerWorker = (float) config('scaling.thresholds.target_backlog_per_worker', 50);
        $scaleUpThreshold = (float) config('scaling.thresholds.pressure_scale_up_threshold', 100.0);
        $scaleDownThreshold = (float) config('scaling.thresholds.pressure_scale_down_threshold', 30.0);

        $desiredWorkers = $currentWorkers;
        $scalingAction = 'STABLE';
        $reason = 'Queue pressure within operational SLA parameters';

        if ($metrics['pressure_score'] >= $scaleUpThreshold) {
            // Need to scale out: calculate based on backlog depth + wait time severity
            $calculatedWorkers = (int) ceil($metrics['size'] / max(1.0, $targetBacklogPerWorker));
            if ($metrics['wait_time_seconds'] > (float) config('scaling.thresholds.target_wait_time_seconds', 15)) {
                $calculatedWorkers = (int) ceil($calculatedWorkers * 1.5); // Add emergency multiplier for wait time SLA breaches
            }

            $desiredWorkers = min($maxWorkers, max($currentWorkers + 1, $calculatedWorkers));
            $scalingAction = 'SCALE_UP';
            $reason = sprintf(
                'Queue pressure score %.1f exceeds threshold %.1f (Wait time: %.1fs, Backlog: %d jobs)',
                $metrics['pressure_score'],
                $scaleUpThreshold,
                $metrics['wait_time_seconds'],
                $metrics['size']
            );
        } elseif ($metrics['pressure_score'] <= $scaleDownThreshold && $currentWorkers > $minWorkers) {
            $desiredWorkers = max($minWorkers, (int) floor($currentWorkers * 0.75));
            $scalingAction = 'SCALE_DOWN';
            $reason = sprintf(
                'Queue pressure score %.1f is below cooldown threshold %.1f',
                $metrics['pressure_score'],
                $scaleDownThreshold
            );
        }

        return [
            'current_workers' => $currentWorkers,
            'desired_workers' => $desiredWorkers,
            'scaling_action' => $scalingAction,
            'reason' => $reason,
            'pressure_metrics' => $metrics,
        ];
    }

    /**
     * Dispatch queue telemetry metrics to the active metric driver.
     *
     * @param  array<string>|null  $queues
     * @return array<int, array{queue: string, size: int, wait_time_seconds: float, pressure_score: float}>
     */
    public function emitTelemetry(?array $queues = null, ?string $connection = null): array
    {
        $queues = $queues ?? config('scaling.queues', ['default', 'high']);
        $emitted = [];

        foreach ($queues as $queue) {
            $evaluation = $this->evaluateQueuePressure($queue, $connection);
            $tags = ['queue' => $queue, 'connection' => $connection ?? config('queue.default', 'database')];

            $this->dispatcher->dispatch('queue_size', $evaluation['size'], $tags);
            $this->dispatcher->dispatch('wait_time_seconds', $evaluation['wait_time_seconds'], $tags);
            $this->dispatcher->dispatch('queue_pressure', $evaluation['pressure_score'], $tags);

            $emitted[] = $evaluation;
        }

        return $emitted;
    }
}
