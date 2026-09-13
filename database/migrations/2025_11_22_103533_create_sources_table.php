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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            
            // Relation avec match
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            
            // Type de source (A, B ou C)
            $table->enum('source_type', ['A', 'B', 'C']);
            
            // Prédictions (stockées en JSON pour flexibilité)
            // Contient: winner, overUnder, btts, doubleChance, exactScore, htft
            $table->json('predictions');
            
            $table->timestamps();
            
            // Un match ne peut avoir qu'une source de chaque type
            $table->unique(['match_id', 'source_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
