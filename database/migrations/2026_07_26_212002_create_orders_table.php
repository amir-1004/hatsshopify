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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_order_id')->unique();
            $table->foreignId('hat_id')->nullable()->constrained('hats')->nullOnDelete();
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('order_number')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending');
            $table->unsignedInteger('quantity')->default(1);
            $table->json('order_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
