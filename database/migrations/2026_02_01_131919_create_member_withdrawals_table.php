<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Pencairan Simpanan:
     * - Setelah pengajuan keluar disetujui
     * - Mencatat detail pencairan semua jenis simpanan
     * - Setelah pencairan, status anggota berubah menjadi Nonaktif
     */
    public function up(): void
    {
        Schema::create('member_withdrawals', function (Blueprint $table) {
            $table->id();
            
            // Reference to resignation
            $table->foreignId('resignation_id')
                ->constrained('member_resignations')
                ->onDelete('cascade')
                ->comment('Related resignation request');
            
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade')
                ->comment('Member receiving withdrawal');
            
            // Withdrawal details per savings type
            $table->decimal('principal_amount', 15, 2)
                ->default(0)
                ->comment('Simpanan Pokok withdrawn');
            
            $table->decimal('mandatory_amount', 15, 2)
                ->default(0)
                ->comment('Simpanan Wajib withdrawn');
            
            $table->decimal('voluntary_amount', 15, 2)
                ->default(0)
                ->comment('Simpanan Sukarela withdrawn');
            
            $table->decimal('holiday_amount', 15, 2)
                ->default(0)
                ->comment('Simpanan Hari Raya withdrawn');
            
            $table->decimal('total_withdrawal', 15, 2)
                ->default(0)
                ->comment('Total withdrawal amount');
            
            // Payment details
            $table->enum('payment_method', ['cash', 'transfer', 'check'])
                ->default('transfer')
                ->comment('Method of payment');
            
            $table->string('bank_name')->nullable()
                ->comment('Bank name if transfer');
            
            $table->string('account_number')->nullable()
                ->comment('Account number if transfer');
            
            $table->string('account_holder_name')->nullable()
                ->comment('Account holder name');
            
            $table->string('transfer_reference')->nullable()
                ->comment('Transfer reference number');
            
            // Cash account used for withdrawal
            $table->foreignId('cash_account_id')
                ->constrained('cash_accounts')
                ->comment('Cash account used for withdrawal');
            
            $table->date('withdrawal_date')
                ->comment('Date of withdrawal');
            
            // Processing info
            $table->foreignId('processed_by')
                ->constrained('users')
                ->comment('Admin who processed withdrawal');
            
            $table->text('notes')->nullable()
                ->comment('Additional notes');
            
            $table->enum('status', ['pending', 'completed', 'cancelled'])
                ->default('pending')
                ->comment('Withdrawal status');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('resignation_id');
            $table->index('user_id');
            $table->index('cash_account_id');
            $table->index('withdrawal_date');
            $table->index('status');
            
            // Unique: One withdrawal per resignation
            $table->unique('resignation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_withdrawals');
    }
};