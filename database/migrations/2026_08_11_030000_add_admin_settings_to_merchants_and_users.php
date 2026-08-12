<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedBigInteger('minimum_topup_amount')->nullable()->after('topup_url');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('plain_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plain_password');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('minimum_topup_amount');
        });
    }
};
