<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topup_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->decimal('merchant_mdr_percent', 8, 4)->default(0);
            $table->decimal('base_mdr_percent', 8, 4)->default(0);
            $table->decimal('payin_fee_percent', 8, 4)->default(0);
            $table->decimal('settlement_fee_percent', 8, 4)->default(0);
            $table->decimal('ma_fee_percent', 8, 4)->default(0);
            $table->decimal('agent_fee_percent', 8, 4)->default(0);
            $table->decimal('toko_fee_percent', 8, 4)->default(0);
            $table->timestamps();
            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_snapshots');
    }
};
