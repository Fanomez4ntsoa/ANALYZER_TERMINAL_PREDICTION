<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_analysis', function (Blueprint $table) {
            $table->json('match_analyst_output')->nullable()->after('narrative_output');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analysis', function (Blueprint $table) {
            $table->dropColumn('match_analyst_output');
        });
    }
};
