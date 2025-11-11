<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Format: 1-101, 2-201, etc');
            $table->string('name');
            $table->enum('category', ['assets', 'liabilities', 'equity', 'revenue', 'expenses']);
            $table->string('account_type', 50)->nullable()->comment('Cash, Bank, Receivables, etc');
            $table->boolean('is_debit')->default(true)->comment('true=normal debit balance, false=credit');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('code');
            $table->index('category');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};