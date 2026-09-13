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
        Schema::table('recommendations', function (Blueprint $table) {
            $table->decimal('odds', 6, 2)->nullable()->default(null)->change();
            $table->text('logic')->nullable()->change();
            $table->json('predictions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->decimal('odds', 6, 2)->nullable(false)->change();
            $table->text('logic')->nullable(false)->change();
            $table->json('predictions')->nullable(false)->change();
        });
    }
};
