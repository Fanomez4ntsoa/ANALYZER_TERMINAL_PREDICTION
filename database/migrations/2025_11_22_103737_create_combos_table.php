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
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            
            // Relation avec match
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            
            // Nom du combiné (SÉCURITÉ, MATCH OFFENSIF, etc.)
            $table->string('name');
            
            // Liste des paris (JSON array)
            $table->json('bets');
            
            // Cote totale
            $table->decimal('total_odds', 8, 2);
            
            // Confiance
            $table->integer('confidence');
            
            // Priorité
            $table->enum('priority', ['MAX', 'HIGH', 'MEDIUM']);
            
            // Logique d'explication
            $table->text('logic');
            
            // Validation
            $table->boolean('validated')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combos');
    }
};
