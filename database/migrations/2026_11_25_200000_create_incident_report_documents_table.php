<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo sự cố: official BBSC numbers (sequential per site & year) and
 * "chốt biên bản" snapshots of the exported files.
 *
 * One row per maintenance. No foreign key on purpose: deleting a
 * maintenance must not delete the record of an issued document number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incident_report_documents')) {
            return;
        }

        Schema::create('incident_report_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('maintenance_id')->unique();
            $table->string('site_code', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('seq');
            $table->string('number', 120);
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('locked_by')->nullable();
            $table->string('bbsc_path')->nullable();
            $table->string('report_path')->nullable();
            $table->timestamps();

            $table->unique(['site_code', 'year', 'seq'], 'incident_docs_site_year_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_documents');
    }
};
