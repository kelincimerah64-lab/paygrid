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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('merchant_id')->nullable()->index();
            $table->text('merchant_key')->nullable();
            $table->string('merchant_group_name')->nullable();
            $table->string('merchant_group_id')->nullable();
            $table->enum('merchant_type', ['cm', 'script'])->default('cm');
            $table->enum('gateway', ['hilogate', 'alpha', 'artageto', 'kingspay'])->default('hilogate');
            $table->enum('approval_status', ['draft', 'pending_agent', 'pending_ma', 'approved', 'rejected'])->default('draft')->index();
            $table->boolean('topup_enabled')->default(false);
            $table->string('topup_url')->nullable();
            $table->string('transaction_callback_url')->nullable();
            $table->string('withdrawal_callback_url')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('pic_telegram')->nullable();
            $table->string('finance_email')->nullable();
            $table->string('finance_telegram')->nullable();
            $table->string('cs_email')->nullable();
            $table->string('cs_telegram')->nullable();
            $table->decimal('merchant_mdr_percent', 8, 4)->default(0);
            $table->decimal('base_mdr_percent', 8, 4)->default(0);
            $table->decimal('ma_fee_percent', 8, 4)->default(0);
            $table->decimal('agent_fee_percent', 8, 4)->default(0);
            $table->decimal('payin_fee_percent', 8, 4)->default(0);
            $table->integer('disbursement_fee_fixed')->nullable();
            $table->json('onboarding_payload')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_type', 'approval_status']);
            $table->index(['gateway', 'merchant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
