<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // collection|withdrawal
            $table->string('month', 7); // Y-m
            $table->string('status', 20)->default('running'); // running|completed|failed
            $table->unsignedInteger('pages_walked')->default(0);
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_added')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'type', 'month'], 'reconciliation_runs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
