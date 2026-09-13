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
            // Colonne version pour différencier v1.0 (React) et v2.0 (Laravel)
            $table->string('version', 10)->default('2.0')->after('id');
            
            // Stockage JSON des sources brutes (pour réanalyse future)
            $table->json('sources_data')->nullable()->after('context');
            
            // ID React original (pour traçabilité)
            $table->string('react_id', 36)->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['version', 'sources_data', 'react_id']);
        });
    }
};
