<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('odds_over_2_0', 6, 3)->nullable()->after('odds_under_2_5');
            $table->decimal('odds_under_2_0', 6, 3)->nullable()->after('odds_over_2_0');
            $table->decimal('odds_over_2_25', 6, 3)->nullable()->after('odds_under_2_0');
            $table->decimal('odds_under_2_25', 6, 3)->nullable()->after('odds_over_2_25');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'odds_over_2_0',
                'odds_under_2_0',
                'odds_over_2_25',
                'odds_under_2_25',
            ]);
        });
    }
};
