<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fee_menu')->nullable()->after('ma_fee_percent');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->string('engine_type')->nullable()->after('connection_type');
            $table->string('fee_menu')->nullable()->after('ma_fee_percent');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('engine_type')->nullable()->after('merchant_type');
            $table->string('fee_menu')->nullable()->after('merchant_mdr_percent');
        });

        DB::table('agents')->where('connection_type', '<>', 'cm')->update(['engine_type' => 'sc']);
        DB::table('merchants')->where('merchant_type', '<>', 'cm')->update(['engine_type' => 'sc']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fee_menu');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['engine_type', 'fee_menu']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['engine_type', 'fee_menu']);
        });
    }
};
