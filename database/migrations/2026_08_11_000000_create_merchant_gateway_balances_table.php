<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_gateway_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->bigInteger('active_balance')->default(0);
            $table->bigInteger('pending_balance')->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_gateway_balances');
    }
};
