<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes to the `jobs` table for database queue driver workloads.
     *
     * When populating next available jobs:
     * - `WHERE queue = ? AND reserved_at IS NULL AND available_at <= ?`
     *
     * Without composite indexes covering queue + reservation/availability, MySQL performs
     * index merges or filesorts under high concurrency.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['queue', 'reserved_at'], 'jobs_queue_reserved_at_index');
            $table->index(['queue', 'available_at'], 'jobs_queue_available_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_queue_reserved_at_index');
            $table->dropIndex('jobs_queue_available_at_index');
        });
    }
};
