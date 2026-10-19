<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_VALUES = ['hilogate', 'alpha', 'artageto', 'kingspay'];

    private const NEW_VALUES = ['hilogate', 'alpha', 'artageto', 'kingspay', 'magnetopay'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('merchants', function (Blueprint $table) {
                $table->enum('gateway', self::NEW_VALUES)->default('hilogate')->change();
            });
            Schema::table('merchant_registrations', function (Blueprint $table) {
                $table->enum('gateway', self::NEW_VALUES)->default('hilogate')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE merchants MODIFY gateway ENUM('".implode("','", self::NEW_VALUES)."') NOT NULL DEFAULT 'hilogate'");
        DB::statement("ALTER TABLE merchant_registrations MODIFY gateway ENUM('".implode("','", self::NEW_VALUES)."') NOT NULL DEFAULT 'hilogate'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('merchants', function (Blueprint $table) {
                $table->enum('gateway', self::OLD_VALUES)->default('hilogate')->change();
            });
            Schema::table('merchant_registrations', function (Blueprint $table) {
                $table->enum('gateway', self::OLD_VALUES)->default('hilogate')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE merchants MODIFY gateway ENUM('".implode("','", self::OLD_VALUES)."') NOT NULL DEFAULT 'hilogate'");
        DB::statement("ALTER TABLE merchant_registrations MODIFY gateway ENUM('".implode("','", self::OLD_VALUES)."') NOT NULL DEFAULT 'hilogate'");
    }
};
