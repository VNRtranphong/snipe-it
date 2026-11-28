<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kế hoạch bảo trì năm (trình ký): one line per planned maintenance
 * (cinema × system × month) with the staff who will do it, plus the
 * signature names of the year document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_year_plan_lines')) {
            Schema::create('maintenance_year_plan_lines', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedSmallInteger('year');
                $table->string('site', 191);
                $table->string('system', 30);
                $table->unsignedTinyInteger('month');
                $table->date('planned_date')->nullable();
                // One name per line
                $table->text('staff')->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['year', 'month'], 'maintenance_year_plan_lines_year_month_index');
            });
        }

        if (! Schema::hasTable('maintenance_year_plans')) {
            Schema::create('maintenance_year_plans', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedSmallInteger('year')->unique();
                $table->string('place', 191)->nullable();
                $table->string('prepared_by', 191)->nullable();
                $table->string('checked_by', 191)->nullable();
                $table->string('approved_by', 191)->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_year_plans');
        Schema::dropIfExists('maintenance_year_plan_lines');
    }
};
