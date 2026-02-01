<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Pinjaman:
     * - Tambah metode potongan: Gaji dan Jasa Pelayanan
     * - Fitur pelunasan (hanya bayar pokok, tanpa bunga)
     * - Update status pinjaman
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Metode potongan
            $table->enum('deduction_method', ['none', 'salary', 'service_allowance', 'mixed'])
                ->default('none')
                ->after('status')
                ->comment('none=bayar manual, salary=potong gaji, service_allowance=potong jasa, mixed=kombinasi');
            
            $table->decimal('salary_deduction_percentage', 5, 2)
                ->default(0)
                ->after('deduction_method')
                ->comment('Percentage of salary to deduct (if applicable)');
            
            $table->decimal('service_allowance_deduction_percentage', 5, 2)
                ->default(0)
                ->after('salary_deduction_percentage')
                ->comment('Percentage of service allowance to deduct (if applicable)');
            
            // Pelunasan dipercepat
            $table->boolean('is_early_settlement')
                ->default(false)
                ->after('service_allowance_deduction_percentage')
                ->comment('Flag if loan was settled early');
            
            $table->date('settlement_date')
                ->nullable()
                ->after('is_early_settlement')
                ->comment('Date of early settlement');
            
            $table->decimal('settlement_amount', 15, 2)
                ->default(0)
                ->after('settlement_date')
                ->comment('Amount paid for early settlement (principal only)');
            
            $table->foreignId('settled_by')
                ->nullable()
                ->after('settlement_amount')
                ->constrained('users')
                ->comment('Admin who processed the settlement');
            
            $table->text('settlement_notes')
                ->nullable()
                ->after('settled_by')
                ->comment('Notes about the settlement');
            
            // Add indexes
            $table->index('deduction_method');
            $table->index('is_early_settlement');
            $table->index('settlement_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['deduction_method']);
            $table->dropIndex(['is_early_settlement']);
            $table->dropIndex(['settlement_date']);
            
            $table->dropForeign(['settled_by']);
            
            $table->dropColumn([
                'deduction_method',
                'salary_deduction_percentage',
                'service_allowance_deduction_percentage',
                'is_early_settlement',
                'settlement_date',
                'settlement_amount',
                'settled_by',
                'settlement_notes'
            ]);
        });
    }
};