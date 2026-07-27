<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store design bytes as base64 text instead of raw binary: PDO can't
     * bind raw binary into Postgres bytea as a plain string parameter.
     */
    public function up(): void
    {
        Schema::table('design_files', function (Blueprint $table) {
            $table->dropColumn('data');
        });

        Schema::table('design_files', function (Blueprint $table) {
            $table->text('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_files', function (Blueprint $table) {
            $table->dropColumn('data');
        });

        Schema::table('design_files', function (Blueprint $table) {
            $table->binary('data')->nullable();
        });
    }
};
