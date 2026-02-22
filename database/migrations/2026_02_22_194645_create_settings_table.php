<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index(); // 'cooperative', 'loan', 'savings', 'system'
            $table->string('key')->index(); // 'name', 'interest_rate', etc
            $table->json('payload')->nullable(); // the actual value
            $table->string('type')->default('string'); // 'string', 'boolean', 'integer', 'float', 'json'
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};