<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->decimal('merchant_mdr_percent', 8, 4)->default(0);
            $table->decimal('base_mdr_percent', 8, 4)->default(0);
            $table->decimal('payin_fee_percent', 8, 4)->default(0);
            $table->decimal('settlement_fee_percent', 8, 4)->default(0);
            $table->decimal('ma_fee_percent', 8, 4)->default(0);
            $table->decimal('agent_fee_percent', 8, 4)->default(0);
            $table->decimal('toko_fee_percent', 8, 4)->default(0);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_schemes');
    }
};
