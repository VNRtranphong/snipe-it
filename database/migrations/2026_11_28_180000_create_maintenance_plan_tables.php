<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch bảo trì: one "run" = one periodic maintenance of one system
 * (hệ máy chiếu / hệ âm thanh) at one cinema. Each run creates one
 * maintenance per hall (item = representative asset of that hall).
 *
 * No foreign keys on purpose: deleting a maintenance must not delete the
 * history of the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_plan_runs')) {
            Schema::create('maintenance_plan_runs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('site', 191);
                $table->string('system', 30);
                // open | done | skipped
                $table->string('status', 20)->default('open');
                $table->date('due_date')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->boolean('result_good')->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('completed_by')->nullable();
                $table->timestamps();

                $table->index(['site', 'system'], 'maintenance_plan_runs_site_system_index');
            });
        }

        if (! Schema::hasTable('maintenance_plan_items')) {
            Schema::create('maintenance_plan_items', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('run_id')->index();
                $table->unsignedInteger('maintenance_id')->unique();
                $table->string('hall', 50)->nullable();
                // Snapshot of the hall's devices at start: [{role, name, model, serial, asset_id}]
                $table->text('devices')->nullable();
                $table->boolean('good')->nullable();
                // B-chain sound levels measured after maintenance (dB): {"left": 85, ...}
                $table->text('levels')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_plan_items');
        Schema::dropIfExists('maintenance_plan_runs');
    }
};
