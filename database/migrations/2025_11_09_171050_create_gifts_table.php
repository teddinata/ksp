<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->onDelete('cascade');
            $table->text('gift_description');
            $table->decimal('gift_amount', 15, 2)->nullable()->comment('If gift is money');
            $table->date('given_date');
            $table->foreignId('given_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('cash_account_id');
            $table->index('given_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};