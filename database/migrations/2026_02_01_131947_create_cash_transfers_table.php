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
     * CATATAN REVISI - Transfer Kas:
     * - Menyalurkan dana dari Kas Besar ke kas lainnya
     * - Tercatat otomatis di jurnal
     * - Support untuk transfer antar kas
     */
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            
            // Transfer number
            $table->string('transfer_number')
                ->unique()
                ->comment('Format: TRF/YY/MM/NNNN');
            
            // Source and destination
            $table->foreignId('from_cash_account_id')
                ->constrained('cash_accounts')
                ->onDelete('restrict')
                ->comment('Source cash account');
            
            $table->foreignId('to_cash_account_id')
                ->constrained('cash_accounts')
                ->onDelete('restrict')
                ->comment('Destination cash account');
            
            // Amount
            $table->decimal('amount', 15, 2)
                ->comment('Transfer amount');
            
            $table->date('transfer_date')
                ->comment('Date of transfer');
            
            // Purpose and notes
            $table->string('purpose')
                ->comment('Purpose of transfer');
            
            $table->text('notes')
                ->nullable()
                ->comment('Additional notes');
            
            // Reference to journal entry (auto-generated)
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained('journals')
                ->onDelete('set null')
                ->comment('Auto-generated journal entry');
            
            // Processing info
            $table->foreignId('created_by')
                ->constrained('users')
                ->comment('Admin who created the transfer');
            
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->comment('Admin who approved the transfer');
            
            $table->timestamp('approved_at')
                ->nullable()
                ->comment('When the transfer was approved');
            
            // Status
            $table->enum('status', ['pending', 'approved', 'completed', 'cancelled'])
                ->default('pending')
                ->comment('Transfer status');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('transfer_number');
            $table->index('from_cash_account_id');
            $table->index('to_cash_account_id');
            $table->index('transfer_date');
            $table->index('status');
            $table->index('journal_id');
        });
        
        // Add check constraint using raw SQL (if MySQL/PostgreSQL)
        // Validation will be handled in application layer (Controller/Model)
        // DB::statement('ALTER TABLE cash_transfers ADD CONSTRAINT check_different_accounts CHECK (from_cash_account_id != to_cash_account_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};