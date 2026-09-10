<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite and timestamp indexes to `failed_jobs` to eliminate full table scans
     * during pruning, status checks, and queue-specific health monitoring.
     *
     * SportMonks Case Study:
     * Full table scans on `failed_jobs` accounted for 75% of total database CPU under heavy
     * throughput. Composite indexing reduced query execution time from 8.7s to 0.1ms (87,000x win).
     */
    public function up(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            // Composite index for queue-specific pruning and status checks
            $table->index(['queue', 'failed_at'], 'failed_jobs_queue_failed_at_index');

            // Standalone index for global retention pruning (e.g. queue:prune-failed --hours=48)
            $table->index('failed_at', 'failed_jobs_failed_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropIndex('failed_jobs_queue_failed_at_index');
            $table->dropIndex('failed_jobs_failed_at_index');
        });
    }
};
