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
        Schema::create('match_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('recommendation_id')->nullable()->constrained('recommendations')->onDelete('cascade');
            $table->boolean('is_combo')->default(false);
            $table->integer('combo_index')->nullable();
            $table->boolean('validated')->default(false);
            // Défaut explicite : indépendant d'explicit_defaults_for_timestamp (2026_09_27_000001)
            $table->timestamp('validated_at')->useCurrent();
            $table->timestamps();
            
            // Index pour performance
            $table->index(['match_id', 'recommendation_id']);
            $table->index(['match_id', 'is_combo', 'combo_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_validations');
    }
};
