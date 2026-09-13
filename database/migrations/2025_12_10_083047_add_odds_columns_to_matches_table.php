<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Over/Under 2.5
            $table->decimal('odds_over_2_5', 6, 3)->nullable()->after('odds_away');
            $table->decimal('odds_under_2_5', 6, 3)->nullable()->after('odds_over_2_5');
            
            // BTTS (Both Teams To Score)
            $table->decimal('odds_btts_yes', 6, 3)->nullable()->after('odds_under_2_5');
            $table->decimal('odds_btts_no', 6, 3)->nullable()->after('odds_btts_yes');
            
            // Double Chance
            $table->decimal('odds_dc_1x', 6, 3)->nullable()->after('odds_btts_no');
            $table->decimal('odds_dc_12', 6, 3)->nullable()->after('odds_dc_1x');
            $table->decimal('odds_dc_x2', 6, 3)->nullable()->after('odds_dc_12');
            
            // Total Domicile (Home Team Total)
            $table->decimal('odds_home_over_0_5', 6, 3)->nullable()->after('odds_dc_x2');
            $table->decimal('odds_home_under_0_5', 6, 3)->nullable()->after('odds_home_over_0_5');
            $table->decimal('odds_home_over_1_5', 6, 3)->nullable()->after('odds_home_under_0_5');
            $table->decimal('odds_home_under_1_5', 6, 3)->nullable()->after('odds_home_over_1_5');
            
            // Total Extérieur (Away Team Total)
            $table->decimal('odds_away_over_0_5', 6, 3)->nullable()->after('odds_home_under_1_5');
            $table->decimal('odds_away_under_0_5', 6, 3)->nullable()->after('odds_away_over_0_5');
            $table->decimal('odds_away_over_1_5', 6, 3)->nullable()->after('odds_away_under_0_5');
            $table->decimal('odds_away_under_1_5', 6, 3)->nullable()->after('odds_away_over_1_5');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'odds_over_2_5',
                'odds_under_2_5',
                'odds_btts_yes',
                'odds_btts_no',
                'odds_dc_1x',
                'odds_dc_12',
                'odds_dc_x2',
                'odds_home_over_0_5',
                'odds_home_under_0_5',
                'odds_home_over_1_5',
                'odds_home_under_1_5',
                'odds_away_over_0_5',
                'odds_away_under_0_5',
                'odds_away_over_1_5',
                'odds_away_under_1_5',
            ]);
        });
    }
};
