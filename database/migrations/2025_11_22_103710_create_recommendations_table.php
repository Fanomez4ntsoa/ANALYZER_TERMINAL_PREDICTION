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
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            
            // Relation avec match
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            
            // Marché (winner, overUnder, btts, doubleChance, exactScore, htft)
            $table->string('market');
            
            // Pari recommandé (1, X, 2, Yes, No, Over, Under, 1X, X2, etc.)
            $table->string('bet');
            
            // Confiances
            $table->integer('confidence');           // Confiance finale (Layer 2 si dispo)
            $table->integer('confidence_layer1')->nullable();  // Confiance originale Layer 1
            
            // Score et niveau de priorité
            $table->integer('score');
            $table->tinyInteger('level');            // 1, 2, 3, 4
            
            // Cote estimée
            $table->decimal('odds', 6, 2);
            
            // Consensus
            $table->enum('consensus_type', ['TOTAL', 'MAJORITÉ', 'CONFLIT']);
            $table->tinyInteger('consensus_agreement');  // 1, 2, ou 3
            
            // Détail des prédictions par source (JSON)
            $table->json('predictions');
            
            // Règle spéciale appliquée
            $table->string('special_rule')->nullable();
            $table->text('special_rule_reason')->nullable();
            
            // Logique d'explication
            $table->text('logic');
            
            // Warnings (JSON array)
            $table->json('warnings')->nullable();
            
            // Cohérence interne
            $table->boolean('coherent')->default(true);
            
            // Validation du résultat
            $table->boolean('validated')->nullable();  // null = pas validé, true = gagné, false = perdu
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
