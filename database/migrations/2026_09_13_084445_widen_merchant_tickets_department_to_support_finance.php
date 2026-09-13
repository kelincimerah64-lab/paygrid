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
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropIndex(['department', 'status']);
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->string('department_tmp', 20)->nullable()->after('department');
        });

        DB::statement('UPDATE merchant_tickets SET department_tmp = department');

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('department');
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->string('department', 20)->nullable()->after('merchant_id');
        });

        DB::statement('UPDATE merchant_tickets SET department = department_tmp');

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('department_tmp');
            $table->index(['department', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropIndex(['department', 'status']);
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->enum('department_tmp', ['cs', 'tech'])->nullable()->after('department');
        });

        DB::statement("UPDATE merchant_tickets SET department_tmp = department WHERE department IN ('cs', 'tech')");

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('department');
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->enum('department', ['cs', 'tech'])->after('merchant_id');
        });

        DB::statement('UPDATE merchant_tickets SET department = department_tmp');

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('department_tmp');
            $table->index(['department', 'status']);
        });
    }
};
