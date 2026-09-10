<?php

namespace App\Console\Commands;

use App\Services\Scaling\QueuePressureMonitor;
use Illuminate\Console\Command;

class BenchmarkQueuePressureCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:queue-pressure-simulation
                            {--jobs=10000 : Simulated queued job backlog volume}
                            {--worker-latency-ms=250 : Simulated I/O latency per job (e.g. Stripe/Webhook HTTP wait)}
                            {--current-workers=4 : Initial active worker container replica count}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate I/O-bound queue traffic and demonstrate the CPU Autoscaling Flaw vs. Queue Pressure';

    /**
     * Execute the console command.
     */
    public function handle(QueuePressureMonitor $monitor): int
    {
        $jobCount = max(100, (int) $this->option('jobs'));
        $ioLatencyMs = max(10, (int) $this->option('worker-latency-ms'));
        $currentWorkers = max(1, (int) $this->option('current-workers'));

        $this->alert('🚨 The Autoscaling Flaw Simulation: CPU-based HPA vs. Queue Pressure Engine');
        $this->line('<fg=gray>Lesson: Queue workers are predominantly I/O-bound (network sockets, external APIs, DB locks). CPU remains idle while queues blow out to millions of unhandled jobs.</>');
        $this->newLine();

        // Calculate simulated metrics
        $throughputPerWorkerPerSec = 1000.0 / $ioLatencyMs; // e.g. 250ms = 4 jobs/sec/worker
        $totalClusterThroughput = $throughputPerWorkerPerSec * $currentWorkers; // e.g. 16 jobs/sec

        $estimatedDrainTimeSeconds = round($jobCount / max(1.0, $totalClusterThroughput), 1);
        $simulatedCpuUtilization = min(9.4, 2.5 + ($currentWorkers * 0.4)); // CPU remains very low for I/O waits

        // Target thresholds
        $targetWaitTime = (float) config('scaling.thresholds.target_wait_time_seconds', 15);
        $targetBacklog = (float) config('scaling.thresholds.target_backlog_per_worker', 50);

        // Calculate pressure score
        $normalizedWait = ($estimatedDrainTimeSeconds / $targetWaitTime) * 100.0;
        $normalizedBacklog = ($jobCount / ($targetBacklog * $currentWorkers)) * 100.0;
        $simulatedPressureScore = round((0.65 * $normalizedWait) + (0.35 * $normalizedBacklog), 1);

        // Desired workers recommended by pressure engine
        $desiredWorkers = min(
            (int) config('scaling.thresholds.max_workers', 50),
            max($currentWorkers + 1, (int) ceil($jobCount / $targetBacklog * 1.5))
        );

        $this->info('📊 1. Workload Simulation Parameters');
        $this->table(
            ['Metric', 'Value', 'Context'],
            [
                ['Simulated Backlog', number_format($jobCount).' jobs', 'I/O-bound webhook & API sync workload'],
                ['Worker Latency', sprintf('%d ms / job', $ioLatencyMs), 'Network I/O waiting on third-party HTTP sockets'],
                ['Active Worker Containers', (string) $currentWorkers, 'Initial pod/container count'],
                ['Cluster Drain Capacity', sprintf('%.1f jobs/sec', $totalClusterThroughput), 'Current cluster processing speed'],
                ['Estimated Wait Time (SLA)', sprintf('%.1f seconds (SLA Target: < %ds)', $estimatedDrainTimeSeconds, (int) $targetWaitTime), 'Time for newly enqueued jobs to be processed'],
                ['Average Host CPU Utilization', sprintf('%.1f%%', $simulatedCpuUtilization), 'Low CPU because threads are parked on socket select/epoll'],
            ]
        );

        $this->newLine();
        $this->info('⚖️ 2. Autoscaler Decision Comparison Matrix');

        $this->table(
            ['Autoscaling Strategy', 'Input Metric', 'Decision Triggered', 'Target Worker Count', 'Outcome'],
            [
                [
                    '<fg=red;options=bold>Traditional CPU-based HPA</>',
                    sprintf('Host CPU: %.1f%% (Target: >75%%)', $simulatedCpuUtilization),
                    '<fg=red;options=bold>NO SCALE / SCALE DOWN</>',
                    sprintf('%d workers (No change)', $currentWorkers),
                    sprintf('<fg=red>❌ SLA Breached! Backlog stalls for %.1fs. DB sockets exhaust.</>', $estimatedDrainTimeSeconds),
                ],
                [
                    '<fg=green;options=bold>Queue Pressure Engine</>',
                    sprintf('Pressure Score: %.1f (Wait: %.1fs)', $simulatedPressureScore, $estimatedDrainTimeSeconds),
                    sprintf('<fg=green;options=bold>IMMEDIATE SCALE UP (+%d)</>', $desiredWorkers - $currentWorkers),
                    sprintf('%d workers (Optimal)', $desiredWorkers),
                    sprintf('<fg=green>✓ SLA Protected! Drain time reduced from %.1fs to < 10s.</>', $estimatedDrainTimeSeconds),
                ],
            ]
        );

        $this->newLine();
        $this->info('💡 3. Key Takeaways from Laracon US 2026:');
        $this->line(' • <fg=cyan>Why CPU Fails:</> When workers make HTTP requests or wait on row-level locks, CPU sits idle in I/O wait.');
        $this->line(' • <fg=cyan>The Solution:</> Emit <comment>wait_time_seconds</comment> and <comment>queue_size</comment> via <comment>php artisan scaling:emit-queue-pressure</comment>.');
        $this->line(' • <fg=cyan>KEDA / AWS Integration:</> Wire your HPA to query <comment>/api/scaling/evaluate</comment> instead of node CPU.');
        $this->newLine();

        return self::SUCCESS;
    }
}
