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
            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->onDelete('set null');
            $table->enum('journal_type', ['general', 'special', 'adjusting', 'closing', 'reversing'])->default('general');
            $table->text('description');
            $table->date('transaction_date');
            $table->decimal('total_debit', 15, 2)->default(0)->comment('Total debit amount');
            $table->decimal('total_credit', 15, 2)->default(0)->comment('Total credit amount');
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_locked')->default(false)->comment('Locked after period closing');
            $table->boolean('is_balanced')->default(false)->comment('Debit = Credit check');
            $table->string('reference_type')->nullable()->comment('Polymorphic: Saving, Loan, etc');
            $table->unsignedBigInteger('reference_id')->nullable()->comment('ID of referenced record');
            $table->timestamps();
            
            // Indexes
            $table->index('journal_number');
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