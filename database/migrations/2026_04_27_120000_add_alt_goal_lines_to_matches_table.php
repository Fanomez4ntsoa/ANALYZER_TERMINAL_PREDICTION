<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('odds_over_1_5', 6, 3)->nullable()->after('odds_under_2_5');
            $table->decimal('odds_under_1_5', 6, 3)->nullable()->after('odds_over_1_5');
            $table->decimal('odds_over_3_5', 6, 3)->nullable()->after('odds_under_1_5');
            $table->decimal('odds_under_3_5', 6, 3)->nullable()->after('odds_over_3_5');
            $table->decimal('odds_over_4_5', 6, 3)->nullable()->after('odds_under_3_5');
            $table->decimal('odds_under_4_5', 6, 3)->nullable()->after('odds_over_4_5');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'odds_over_1_5',
                'odds_under_1_5',
                'odds_over_3_5',
                'odds_under_3_5',
                'odds_over_4_5',
                'odds_under_4_5',
            ]);
        });
    }
};
