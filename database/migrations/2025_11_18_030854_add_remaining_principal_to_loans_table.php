<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('remaining_principal', 15, 2)
                ->default(0)
                ->after('installment_amount')
                ->comment('Sisa pokok pinjaman yang belum dibayar');
            
            $table->index('remaining_principal');
        });

        // Set initial remaining_principal = principal_amount untuk existing loans
        DB::table('loans')
            ->whereIn('status', ['approved', 'disbursed', 'active'])
            ->update([
                'remaining_principal' => DB::raw('principal_amount')
            ]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['remaining_principal']);
            $table->dropColumn('remaining_principal');
        });
    }
};