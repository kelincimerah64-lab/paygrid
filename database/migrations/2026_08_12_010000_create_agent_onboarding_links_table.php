<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_onboarding_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token')->unique();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_telegram')->nullable();
            $table->enum('status', ['active', 'used', 'expired'])->default('active')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_onboarding_links');
    }
};
