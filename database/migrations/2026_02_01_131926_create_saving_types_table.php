<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CATATAN REVISI - Master Jenis Simpanan:
     * - Admin dapat menambah/mengubah jenis simpanan tanpa hardcode
     * - Flexible, tidak terikat dengan 4 jenis default saja
     * - Support untuk jenis simpanan custom
     */
    public function up(): void
    {
        Schema::create('saving_types', function (Blueprint $table) {
            $table->id();
            
            // Type identification
            $table->string('code', 20)
                ->unique()
                ->comment('Unique code: POKOK, WAJIB, SUKARELA, etc');
            
            $table->string('name')
                ->comment('Display name: Simpanan Pokok, etc');
            
            $table->text('description')->nullable()
                ->comment('Description of this savings type');
            
            // Type characteristics
            $table->boolean('is_mandatory')
                ->default(false)
                ->comment('Is this a mandatory savings type?');
            
            $table->boolean('is_withdrawable')
                ->default(true)
                ->comment('Can members withdraw from this type?');
            
            $table->decimal('minimum_amount', 15, 2)
                ->default(0)
                ->comment('Minimum deposit amount');
            
            $table->decimal('maximum_amount', 15, 2)
                ->nullable()
                ->comment('Maximum deposit amount (null = unlimited)');
            
            // Interest settings
            $table->boolean('has_interest')
                ->default(true)
                ->comment('Does this type earn interest?');
            
            $table->decimal('default_interest_rate', 5, 2)
                ->default(0)
                ->comment('Default interest rate percentage');
            
            // Status
            $table->boolean('is_active')
                ->default(true)
                ->comment('Is this type currently active?');
            
            $table->integer('display_order')
                ->default(0)
                ->comment('Order for display in UI');
            
            // Metadata
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->comment('Admin who created this type');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('code');
            $table->index('is_active');
            $table->index('is_mandatory');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saving_types');
    }
};