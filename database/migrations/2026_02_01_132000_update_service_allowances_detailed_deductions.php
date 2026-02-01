<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Jasa Pelayanan:
     * - Import data via Excel
     * - Detail/rincian potongan per jenis pinjaman
     * - Tambahan fitur potongan jasa pelayanan tambahan
     */
    public function up(): void
    {
        Schema::table('service_allowances', function (Blueprint $table) {
            // Add loan_id reference for tracking which loan is being deducted
            $table->foreignId('loan_id')
                ->nullable()
                ->after('user_id')
                ->constrained('loans')
                ->onDelete('set null')
                ->comment('Specific loan being paid via service allowance');
            
            // Detailed deduction info
            $table->integer('installment_number')
                ->nullable()
                ->after('loan_id')
                ->comment('Which installment (tenor ke-berapa)');
            
            $table->decimal('principal_deduction', 15, 2)
                ->default(0)
                ->after('installment_number')
                ->comment('Potongan pokok pinjaman');
            
            $table->decimal('interest_deduction', 15, 2)
                ->default(0)
                ->after('principal_deduction')
                ->comment('Potongan bunga pinjaman');
            
            $table->decimal('other_deductions', 15, 2)
                ->default(0)
                ->after('interest_deduction')
                ->comment('Potongan lain-lain');
            
            $table->decimal('total_deductions', 15, 2)
                ->default(0)
                ->after('other_deductions')
                ->comment('Total semua potongan');
            
            // Net amount after deductions
            $table->decimal('net_amount', 15, 2)
                ->default(0)
                ->after('remaining_amount')
                ->comment('Jumlah bersih setelah potongan (remaining_amount - total_deductions)');
            
            // Reference to installment if applicable
            $table->foreignId('installment_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('installments')
                ->onDelete('set null')
                ->comment('Specific installment being paid');
            
            // Reference to journal entry
            $table->foreignId('journal_id')
                ->nullable()
                ->after('distributed_by')
                ->constrained('journals')
                ->onDelete('set null')
                ->comment('Auto-generated journal entry');
            
            // Add indexes
            $table->index('loan_id');
            $table->index('installment_id');
            $table->index('installment_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_allowances', function (Blueprint $table) {
            $table->dropForeign(['loan_id']);
            $table->dropForeign(['installment_id']);
            $table->dropForeign(['journal_id']);
            
            $table->dropIndex(['loan_id']);
            $table->dropIndex(['installment_id']);
            $table->dropIndex(['installment_number']);
            
            $table->dropColumn([
                'loan_id',
                'installment_id',
                'installment_number',
                'principal_deduction',
                'interest_deduction',
                'other_deductions',
                'total_deductions',
                'net_amount',
                'journal_id'
            ]);
        });
    }
};