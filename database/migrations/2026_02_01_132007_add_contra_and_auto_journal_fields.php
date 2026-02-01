<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Akuntansi:
     * - Penambahan Akun Kontra
     * - Seluruh jurnal dibuat otomatis oleh sistem
     * - Jurnal tidak dapat diedit manual
     */
    public function up(): void
    {
        // Update Chart of Accounts
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('is_contra')
                ->default(false)
                ->after('is_debit')
                ->comment('true = akun kontra (berlawanan dengan kategorinya)');
            
            $table->text('contra_description')
                ->nullable()
                ->after('is_contra')
                ->comment('Penjelasan penggunaan akun kontra');
            
            $table->index('is_contra');
        });
        
        // Update Journals
        Schema::table('journals', function (Blueprint $table) {
            $table->boolean('is_auto_generated')
                ->default(false)
                ->after('is_locked')
                ->comment('true = jurnal otomatis dari sistem, false = manual');
            
            $table->boolean('is_editable')
                ->default(false)
                ->after('is_auto_generated')
                ->comment('true = bisa diedit, false = tidak bisa diedit');
            
            $table->string('source_module')
                ->nullable()
                ->after('reference_id')
                ->comment('Module that generated this journal: savings, loans, transfers, etc');
            
            $table->index('is_auto_generated');
            $table->index('is_editable');
            $table->index('source_module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['is_contra']);
            $table->dropColumn(['is_contra', 'contra_description']);
        });
        
        Schema::table('journals', function (Blueprint $table) {
            $table->dropIndex(['is_auto_generated']);
            $table->dropIndex(['is_editable']);
            $table->dropIndex(['source_module']);
            
            $table->dropColumn([
                'is_auto_generated',
                'is_editable',
                'source_module'
            ]);
        });
    }
};