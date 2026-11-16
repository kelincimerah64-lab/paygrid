<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ma_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('apply_scope', 20)->default('all');
            $table->timestamps();
        });

        Schema::create('commission_config_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_config_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('enabled')->default(false);
            $table->decimal('rate_percent', 8, 4)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['commission_config_id', 'day_of_week'], 'commission_config_days_unique');
        });

        Schema::create('commission_config_merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['commission_config_id', 'merchant_id'], 'commission_config_merchants_unique');
        });

        Schema::create('merchant_commission_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_config_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date')->index();
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('rate_percent', 8, 4);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->date('report_date');
            $table->timestamps();

            $table->unique(['merchant_id', 'transaction_date'], 'merchant_commission_reports_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_commission_reports');
        Schema::dropIfExists('commission_config_merchants');
        Schema::dropIfExists('commission_config_days');
        Schema::dropIfExists('commission_configs');
    }
};
