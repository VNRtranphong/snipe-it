<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch bảo trì: answers of "A: Kiểm tra trước khi thực hiện" per hall
 * (text for projector / server, OK / không ổn for the sound devices),
 * printed in the Service Report. Skipped when the column already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_plan_items') || Schema::hasColumn('maintenance_plan_items', 'checklist')) {
            return;
        }

        Schema::table('maintenance_plan_items', function (Blueprint $table) {
            $table->text('checklist')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_plan_items', 'checklist')) {
            Schema::table('maintenance_plan_items', function (Blueprint $table) {
                $table->dropColumn('checklist');
            });
        }
    }
};
