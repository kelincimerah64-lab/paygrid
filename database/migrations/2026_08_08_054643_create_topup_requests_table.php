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
        Schema::create('topup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway')->default('hilogate')->index();
            $table->string('data_source')->default('gateway_pull')->index();
            $table->string('payment_id')->nullable()->index();
            $table->string('gateway_ref_id')->nullable()->unique();
            $table->string('rrn')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->enum('status', ['pending', 'success', 'expired', 'failed', 'rejected'])->default('pending')->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->boolean('is_processed')->default(false)->index();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checked_by_email')->nullable();
            $table->string('checked_by_role')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'submitted_at']);
            $table->index(['merchant_id', 'is_processed', 'submitted_at']);
            $table->index(['status', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topup_requests');
    }
};
