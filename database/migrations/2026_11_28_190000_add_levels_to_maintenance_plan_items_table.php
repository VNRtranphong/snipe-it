<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch bảo trì: B-chain sound levels (dB) typed in after a sound
 * maintenance, printed in the Service Report "B chian" column.
 * Skipped when the create migration already added the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_plan_items') || Schema::hasColumn('maintenance_plan_items', 'levels')) {
            return;
        }

        Schema::table('maintenance_plan_items', function (Blueprint $table) {
            $table->text('levels')->nullable()->after('good');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_plan_items', 'levels')) {
            Schema::table('maintenance_plan_items', function (Blueprint $table) {
                $table->dropColumn('levels');
            });
        }
    }
};
