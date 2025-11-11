<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->onDelete('cascade');
            $table->enum('transaction_type', ['savings', 'loans']);
            $table->decimal('rate_percentage', 5, 2)->comment('In percentage, example: 12.50');
            $table->date('effective_date');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            
            // Indexes
            $table->index('cash_account_id');
            $table->index('transaction_type');
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_rates');
    }
};