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
     * CATATAN REVISI:
     * - Update savings table untuk menggunakan saving_types_id
     * - Migrasi data existing dari enum ke foreign key
     * - Maintain backward compatibility
     */
    public function up(): void
    {
        // Step 1: Add new column
        Schema::table('savings', function (Blueprint $table) {
            $table->foreignId('saving_type_id')
                ->nullable()
                ->after('cash_account_id')
                ->constrained('saving_types')
                ->onDelete('restrict')
                ->comment('Reference to saving_types table');
            
            $table->index('saving_type_id');
        });
        
        // Step 2: Seed default saving types if not exists
        $this->seedDefaultSavingTypes();
        
        // Step 3: Migrate existing data
        $this->migrateExistingData();
        
        // Step 4: Make saving_type_id NOT NULL after migration
        Schema::table('savings', function (Blueprint $table) {
            $table->foreignId('saving_type_id')
                ->nullable(false)
                ->change();
        });
        
        // Step 5: Keep savings_type enum for backward compatibility
        // We'll deprecate it later, but keep for now
    }

    /**
     * Seed default saving types
     */
    private function seedDefaultSavingTypes(): void
    {
        $defaultTypes = [
            [
                'code' => 'POKOK',
                'name' => 'Simpanan Pokok',
                'description' => 'Simpanan yang wajib dibayarkan saat pertama kali menjadi anggota',
                'is_mandatory' => true,
                'is_withdrawable' => false,
                'minimum_amount' => 100000,
                'has_interest' => true,
                'default_interest_rate' => 0,
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'code' => 'WAJIB',
                'name' => 'Simpanan Wajib',
                'description' => 'Simpanan yang wajib dibayarkan setiap bulan oleh anggota',
                'is_mandatory' => true,
                'is_withdrawable' => true,
                'minimum_amount' => 50000,
                'has_interest' => true,
                'default_interest_rate' => 3.00,
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'code' => 'SUKARELA',
                'name' => 'Simpanan Sukarela',
                'description' => 'Simpanan yang dapat dilakukan kapan saja sesuai keinginan anggota',
                'is_mandatory' => false,
                'is_withdrawable' => true,
                'minimum_amount' => 10000,
                'has_interest' => true,
                'default_interest_rate' => 5.00,
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'code' => 'HARIRAYA',
                'name' => 'Simpanan Hari Raya',
                'description' => 'Simpanan khusus untuk persiapan hari raya',
                'is_mandatory' => false,
                'is_withdrawable' => true,
                'minimum_amount' => 25000,
                'has_interest' => true,
                'default_interest_rate' => 4.00,
                'is_active' => true,
                'display_order' => 4,
            ],
        ];
        
        foreach ($defaultTypes as $type) {
            DB::table('saving_types')->insertOrIgnore([
                'code' => $type['code'],
                'name' => $type['name'],
                'description' => $type['description'],
                'is_mandatory' => $type['is_mandatory'],
                'is_withdrawable' => $type['is_withdrawable'],
                'minimum_amount' => $type['minimum_amount'],
                'has_interest' => $type['has_interest'],
                'default_interest_rate' => $type['default_interest_rate'],
                'is_active' => $type['is_active'],
                'display_order' => $type['display_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    
    /**
     * Migrate existing savings data to use new saving_types
     */
    private function migrateExistingData(): void
    {
        // Map old enum values to new saving_types codes
        $mapping = [
            'principal' => 'POKOK',
            'mandatory' => 'WAJIB',
            'voluntary' => 'SUKARELA',
            'holiday' => 'HARIRAYA',
        ];
        
        foreach ($mapping as $enumValue => $code) {
            $savingType = DB::table('saving_types')->where('code', $code)->first();
            
            if ($savingType) {
                DB::table('savings')
                    ->where('savings_type', $enumValue)
                    ->whereNull('saving_type_id')
                    ->update(['saving_type_id' => $savingType->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings', function (Blueprint $table) {
            $table->dropForeign(['saving_type_id']);
            $table->dropIndex(['saving_type_id']);
            $table->dropColumn('saving_type_id');
        });
    }
};