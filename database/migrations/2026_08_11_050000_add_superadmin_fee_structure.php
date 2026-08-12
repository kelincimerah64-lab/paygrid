<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact')->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->decimal('base_hg_percent', 8, 4)->default(0)->after('merchant_id');
            $table->string('connection_type')->nullable()->after('base_hg_percent');
            $table->decimal('connection_fee_percent', 8, 4)->default(0)->after('connection_type');
            $table->string('settlement_method')->nullable()->after('connection_fee_percent');
            $table->decimal('settlement_fee_percent', 8, 4)->default(0)->after('settlement_method');
            $table->decimal('ma_fee_percent', 8, 4)->default(0)->after('settlement_fee_percent');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('ma_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->decimal('base_hg_percent', 8, 4)->default(0)->after('hg_group_id');
            $table->string('connection_type')->nullable()->after('base_hg_percent');
            $table->decimal('connection_fee_percent', 8, 4)->default(0)->after('connection_type');
            $table->string('settlement_method')->nullable()->after('connection_fee_percent');
            $table->decimal('settlement_fee_percent', 8, 4)->default(0)->after('settlement_method');
            $table->decimal('ma_fee_percent', 8, 4)->default(0)->after('settlement_fee_percent');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('connection_fee_percent', 8, 4)->default(0)->after('base_mdr_percent');
            $table->string('settlement_method')->nullable()->after('connection_fee_percent');
            $table->decimal('settlement_fee_percent', 8, 4)->default(0)->after('settlement_method');
            $table->decimal('toko_fee_percent', 8, 4)->default(0)->after('agent_fee_percent');
        });

        Schema::create('paygrid_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paygrid_settings');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['connection_fee_percent', 'settlement_method', 'settlement_fee_percent', 'toko_fee_percent']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ma_user_id');
            $table->dropColumn(['base_hg_percent', 'connection_type', 'connection_fee_percent', 'settlement_method', 'settlement_fee_percent', 'ma_fee_percent']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contact', 'is_active', 'base_hg_percent', 'connection_type', 'connection_fee_percent', 'settlement_method', 'settlement_fee_percent', 'ma_fee_percent']);
        });
    }
};
