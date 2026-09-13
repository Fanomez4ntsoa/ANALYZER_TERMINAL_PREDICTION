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
            $table->decimal('odds_home', 6, 3)->nullable()->change();
            $table->decimal('odds_draw', 6, 3)->nullable()->change();
            $table->decimal('odds_away', 6, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('odds_home', 6, 3)->nullable(false)->change();
            $table->decimal('odds_draw', 6, 3)->nullable(false)->change();
            $table->decimal('odds_away', 6, 3)->nullable(false)->change();
        });
    }
};
