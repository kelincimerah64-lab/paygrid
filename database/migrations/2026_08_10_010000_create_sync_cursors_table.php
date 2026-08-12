<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('cursor_type')->default('transaction');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_gateway_ref_id')->nullable();
            $table->timestamp('last_payload_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'gateway', 'cursor_type']);
            $table->index(['gateway', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
