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
        Schema::create('fee_menus', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('ma_enabled')->default(true);
            $table->decimal('ma_floor', 5, 2)->default(0);
            $table->boolean('agent_enabled')->default(true);
            $table->decimal('agent_floor', 5, 2)->default(0);
            $table->boolean('merchant_enabled')->default(true);
            $table->decimal('merchant_floor', 5, 2)->default(0);
            $table->timestamps();
        });

        $now = now();
        $seed = [
            ['based', 'Based', 0.80],
            ['h_plus_1', 'Based + H+1', 0.80],
            ['everyday', 'Based + Everyday', 0.85],
            ['same_day', 'Based + Sameday', 0.90],
            ['h_plus_1_sc', 'H+1 + Script', 0.85],
            ['everyday_sc', 'Everyday + Script', 0.90],
            ['same_day_sc', 'Sameday + Script', 0.95],
            ['h_plus_1_api', 'H+1 + API', 0.80],
            ['everyday_api', 'Everyday + API', 0.85],
            ['same_day_api', 'Sameday + API', 0.90],
        ];

        foreach ($seed as $i => [$key, $label, $maFloor]) {
            DB::table('fee_menus')->insert([
                'key' => $key,
                'label' => $label,
                'sort_order' => $i,
                'ma_enabled' => true,
                'ma_floor' => $maFloor,
                'agent_enabled' => true,
                'agent_floor' => 0,
                'merchant_enabled' => true,
                'merchant_floor' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_menus');
    }
};
