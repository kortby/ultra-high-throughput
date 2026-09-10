<?php

namespace App\Console\Commands;

use App\Services\Scaling\QueuePressureMonitor;
use Illuminate\Console\Command;

class EmitQueuePressureCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scaling:emit-queue-pressure
                            {--queue=* : Specific queue(s) to monitor (defaults to config)}
                            {--connection= : Database or Redis queue connection}
                            {--loop : Run continuously in a telemetry polling loop}
                            {--interval=5 : Polling interval in seconds when in loop mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Emit real-time queue pressure (wait time + backlog) telemetry to CloudWatch/Prometheus/Logs';

    /**
     * Execute the console command.
     */
    public function handle(QueuePressureMonitor $monitor): int
    {
        $queues = $this->option('queue');
        if (empty($queues)) {
            $queues = config('scaling.queues', ['default', 'high']);
        }

        $connection = $this->option('connection');
        $loop = (bool) $this->option('loop');
        $interval = max(1, (int) $this->option('interval'));

        $this->info('⚡ Ultra-High-Throughput Queue Pressure Telemetry Engine');
        $this->line(sprintf('Driver: <comment>%s</comment> | Queues: <comment>%s</comment>', config('scaling.default_driver', 'log'), implode(', ', $queues)));
        $this->newLine();

        do {
            $emitted = $monitor->emitTelemetry($queues, $connection);

            $rows = [];
            foreach ($emitted as $entry) {
                $statusColor = match ($entry['status']) {
                    'CRITICAL_CONGESTION' => 'red',
                    'HIGH_PRESSURE' => 'yellow',
                    'MODERATE_LOAD' => 'cyan',
                    default => 'green',
                };

                $rows[] = [
                    $entry['queue'],
                    number_format($entry['size']),
                    sprintf('%.2fs', $entry['wait_time_seconds']),
                    sprintf('%.1f', $entry['pressure_score']),
                    sprintf('<fg=%s;options=bold>%s</>', $statusColor, $entry['status']),
                    now()->toTimeString(),
                ];
            }

            $this->table(
                ['Queue', 'Backlog Size', 'Wait Time', 'Pressure Score', 'Status', 'Timestamp'],
                $rows
            );

            if ($loop) {
                $this->line(sprintf('Sleeping %ds before next telemetry tick... (Press Ctrl+C to stop)', $interval));
                sleep($interval);
            }
        } while ($loop);

        return self::SUCCESS;
    }
}
