<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * We have no persistent disk on Render, so uploaded/rendered design
     * images are stored directly in the database and served back out via a
     * public route (see routes/web.php) so Printful can fetch them by URL.
     */
    public function up(): void
    {
        Schema::create('design_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('mime');
            $table->binary('data');
            $table->unsignedInteger('byte_size');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_files');
    }
};
