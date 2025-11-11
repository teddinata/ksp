<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number')->unique();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->onDelete('set null');
            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->onDelete('set null');
            $table->enum('journal_type', ['general', 'special', 'adjusting', 'closing', 'reversing']);
            $table->text('description');
            $table->date('transaction_date');
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_locked')->default(false)->comment('Locked after period closing');
            $table->string('reference_type')->nullable()->comment('Class name: Saving, Loan, etc');
            $table->unsignedBigInteger('reference_id')->nullable()->comment('ID of referenced record');
            $table->timestamps();
            
            // Indexes
            $table->index('journal_number');
            $table->index('cash_account_id');
            $table->index('accounting_period_id');
            $table->index('journal_type');
            $table->index('transaction_date');
            $table->index('is_locked');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};