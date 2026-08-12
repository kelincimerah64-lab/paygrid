<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway')->default('hilogate')->index();
            $table->string('gateway_merchant_id')->index();
            $table->string('reference');
            $table->string('settlement_type')->nullable();
            $table->date('settlement_date')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('batch_name')->nullable();
            $table->string('batch_from', 40)->nullable();
            $table->string('batch_until', 40)->nullable();
            $table->unsignedInteger('trx_count')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('total_fee')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('merchant_name')->nullable();
            $table->string('merchant_group_name')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('gateway_created_at')->nullable();
            $table->timestamp('gateway_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['gateway_merchant_id', 'reference']);
            $table->index(['merchant_id', 'settlement_date']);
            $table->index(['merchant_id', 'status', 'settlement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlements');
    }
};
