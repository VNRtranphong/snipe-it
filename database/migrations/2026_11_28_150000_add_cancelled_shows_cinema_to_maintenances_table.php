<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo sự cố: split cancelled shows into two numbers.
 *  - `notes`                  => "Số suất hủy tính cho Tech" (existing field, relabelled)
 *  - `cancelled_shows_cinema` => "Số suất hủy tính cho Rạp" (new; never counted in the Tech reports)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenances') || Schema::hasColumn('maintenances', 'cancelled_shows_cinema')) {
            return;
        }

        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_shows_cinema')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenances', 'cancelled_shows_cinema')) {
            Schema::table('maintenances', function (Blueprint $table) {
                $table->dropColumn('cancelled_shows_cinema');
            });
        }
    }
};
