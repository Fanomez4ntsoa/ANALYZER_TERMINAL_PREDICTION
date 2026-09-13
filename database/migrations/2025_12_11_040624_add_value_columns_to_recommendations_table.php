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
            $table->json('value_analysis')->nullable()->after('warnings');
            $table->string('value_verdict', 20)->nullable()->after('value_analysis');
            $table->text('value_message')->nullable()->after('value_verdict');
            $table->text('value_warning')->nullable()->after('value_message');
            $table->decimal('value_bonus', 5, 2)->nullable()->after('value_warning');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'value_analysis',
                'value_verdict',
                'value_message',
                'value_warning',
                'value_bonus',
            ]);
        });
    }
};
