<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo sự cố: remember the cinema / hall of an incident when its BBSC
 * number is issued or the biên bản is locked, so moving the asset later
 * (e.g. back to VP HO) does not move its old incidents with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_report_documents') || Schema::hasColumn('incident_report_documents', 'site')) {
            return;
        }

        Schema::table('incident_report_documents', function (Blueprint $table) {
            $table->string('site', 191)->nullable()->after('number');
            $table->string('hall', 50)->nullable()->after('site');
            $table->string('place_source', 20)->nullable()->after('hall');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('incident_report_documents', 'site')) {
            Schema::table('incident_report_documents', function (Blueprint $table) {
                $table->dropColumn(['site', 'hall', 'place_source']);
            });
        }
    }
};
