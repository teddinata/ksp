<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_allowances', function (Blueprint $table) {
            // Add new columns
            $table->decimal('received_amount', 15, 2)->after('period_year');
            $table->decimal('installment_paid', 15, 2)->default(0)->after('received_amount');
            $table->decimal('remaining_amount', 15, 2)->default(0)->after('installment_paid');
            
            // Update status enum to include 'processed'
            DB::statement("ALTER TABLE service_allowances MODIFY COLUMN status ENUM('pending', 'paid', 'processed', 'cancelled') DEFAULT 'pending'");
        });
        
        // Drop old columns (if they exist)
        if (Schema::hasColumns('service_allowances', ['base_amount', 'savings_bonus', 'loan_bonus', 'total_amount'])) {
            Schema::table('service_allowances', function (Blueprint $table) {
                $table->dropColumn(['base_amount', 'savings_bonus', 'loan_bonus', 'total_amount']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_allowances', function (Blueprint $table) {
            // Restore old columns
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('savings_bonus', 15, 2)->default(0);
            $table->decimal('loan_bonus', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            
            // Drop new columns
            $table->dropColumn(['received_amount', 'installment_paid', 'remaining_amount']);
            
            // Restore old status enum
            DB::statement("ALTER TABLE service_allowances MODIFY COLUMN status ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending'");
        });
    }
};