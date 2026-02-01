<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Modul Anggota Keluar:
     * A. Pengajuan oleh Anggota
     * B. Persetujuan oleh Admin/Manager
     * C. Pencairan Simpanan
     * 
     * Validasi: Tidak dapat keluar jika masih ada pinjaman aktif
     */
    public function up(): void
    {
        Schema::create('member_resignations', function (Blueprint $table) {
            $table->id();
            
            // Member info
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade')
                ->comment('Member who is resigning');
            
            // Resignation details
            $table->text('reason')
                ->comment('Reason for resignation');
            
            $table->date('resignation_date')
                ->comment('Date of resignation request');
            
            // Financial summary at time of resignation
            $table->decimal('principal_savings_balance', 15, 2)
                ->default(0)
                ->comment('Simpanan Pokok balance');
            
            $table->decimal('mandatory_savings_balance', 15, 2)
                ->default(0)
                ->comment('Simpanan Wajib balance');
            
            $table->decimal('voluntary_savings_balance', 15, 2)
                ->default(0)
                ->comment('Simpanan Sukarela balance');
            
            $table->decimal('holiday_savings_balance', 15, 2)
                ->default(0)
                ->comment('Simpanan Hari Raya balance');
            
            $table->decimal('total_savings', 15, 2)
                ->default(0)
                ->comment('Total all savings');
            
            // Loan validation
            $table->boolean('has_active_loans')
                ->default(false)
                ->comment('Flag if member has active loans');
            
            $table->integer('active_loans_count')
                ->default(0)
                ->comment('Number of active loans');
            
            $table->decimal('total_loan_outstanding', 15, 2)
                ->default(0)
                ->comment('Total outstanding loan amount');
            
            // Status workflow
            $table->enum('status', [
                'pending',      // Menunggu persetujuan
                'approved',     // Disetujui, menunggu pencairan
                'completed',    // Pencairan selesai, status member = inactive
                'rejected'      // Ditolak
            ])->default('pending');
            
            // Approval/Rejection
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->comment('Admin/Manager who processed the resignation');
            
            $table->timestamp('processed_at')
                ->nullable()
                ->comment('When the resignation was processed');
            
            $table->text('rejection_reason')
                ->nullable()
                ->comment('Reason for rejection if status = rejected');
            
            $table->text('admin_notes')
                ->nullable()
                ->comment('Internal notes from admin');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('resignation_date');
            $table->index('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_resignations');
    }
};