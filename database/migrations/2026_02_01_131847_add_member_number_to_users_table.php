<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI:
     * - Nomor Anggota bersifat dinamis dengan format: Tahun/Bulan/Urutan Pendaftaran
     * - Contoh: 26/01/01, 26/01/02, dst
     * - Nomor baru dibuat saat registrasi atau re-aktivasi
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nomor Anggota (YY/MM/NN)
            $table->string('member_number', 20)
                ->nullable()
                ->unique()
                ->after('employee_id')
                ->comment('Format: YY/MM/NN - Auto-generated on registration');
            
            // Tanggal registrasi sebagai anggota
            $table->date('registration_date')
                ->nullable()
                ->after('member_number')
                ->comment('Member registration date');
            
            // Status anggota yang lebih detail
            // Update: status sudah ada, tapi kita tambah keterangan
            $table->text('resignation_reason')
                ->nullable()
                ->after('status')
                ->comment('Reason for resignation if status = inactive');
            
            // Add indexes
            $table->index('member_number');
            $table->index('registration_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['member_number']);
            $table->dropIndex(['registration_date']);
            
            $table->dropColumn([
                'member_number',
                'registration_date',
                'resignation_reason'
            ]);
        });
    }
};