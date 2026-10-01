<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway')->default('hilogate')->index();
            $table->string('gateway_withdrawal_id')->unique();
            $table->string('ref_id')->nullable()->index();
            $table->string('status')->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('bank_code')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->timestamp('gateway_created_at')->nullable()->index();
            $table->timestamp('gateway_completed_at')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'gateway_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_withdrawals');
    }
};
