<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Potongan Gaji:
     * - Rekap data gaji & potongan per periode
     * - Disesuaikan dengan nominal pinjaman tiap anggota
     * - Auto-calculate dari loan deduction settings
     */
    public function up(): void
    {
        Schema::create('salary_deductions', function (Blueprint $table) {
            $table->id();
            
            // Period
            $table->integer('period_month')
                ->comment('1-12');
            
            $table->integer('period_year')
                ->comment('YYYY');
            
            // Member info
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade')
                ->comment('Member whose salary is deducted');
            
            // Salary info
            $table->decimal('gross_salary', 15, 2)
                ->default(0)
                ->comment('Gross salary before deductions');
            
            // Deductions breakdown
            $table->decimal('loan_deduction', 15, 2)
                ->default(0)
                ->comment('Total loan installment deductions');
            
            $table->decimal('savings_deduction', 15, 2)
                ->default(0)
                ->comment('Mandatory savings deduction');
            
            $table->decimal('other_deductions', 15, 2)
                ->default(0)
                ->comment('Other deductions');
            
            $table->decimal('total_deductions', 15, 2)
                ->default(0)
                ->comment('Total all deductions');
            
            $table->decimal('net_salary', 15, 2)
                ->default(0)
                ->comment('Net salary after deductions');
            
            // Processing info
            $table->date('deduction_date')
                ->comment('Date when deduction was processed');
            
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->comment('Admin who processed the deduction');
            
            $table->enum('status', ['pending', 'processed', 'paid', 'cancelled'])
                ->default('pending')
                ->comment('Deduction status');
            
            $table->text('notes')
                ->nullable()
                ->comment('Additional notes');
            
            // Reference to journal entry
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained('journals')
                ->onDelete('set null')
                ->comment('Auto-generated journal entry');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('user_id');
            $table->index(['period_month', 'period_year']);
            $table->index('deduction_date');
            $table->index('status');
            
            // Unique: one record per member per period
            $table->unique(['user_id', 'period_month', 'period_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_deductions');
    }
};