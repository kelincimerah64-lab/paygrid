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
        Schema::create('merchant_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token')->unique();
            $table->string('store_name');
            $table->string('engine_name')->nullable();
            $table->enum('merchant_type', ['cm', 'script'])->default('cm');
            $table->enum('gateway', ['hilogate', 'alpha', 'artageto', 'kingspay'])->default('hilogate');
            $table->string('settlement_method')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['draft', 'pending_agent', 'pending_ma', 'approved', 'rejected'])->default('pending_agent')->index();
            $table->timestamp('submitted_to_ma_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_registrations');
    }
};
