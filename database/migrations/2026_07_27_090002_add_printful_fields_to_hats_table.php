<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hats', function (Blueprint $table) {
            $table->unsignedBigInteger('printful_product_id')->nullable();
            $table->unsignedBigInteger('printful_variant_id')->nullable();
            $table->foreignId('design_file_id')->nullable()->constrained('design_files')->nullOnDelete();
            $table->json('mockup_urls')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('design_file_id');
            $table->dropColumn(['printful_product_id', 'printful_variant_id', 'mockup_urls']);
        });
    }
};
