<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'cancelled' to the enum (MySQL only, skip for SQLite)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE installments MODIFY COLUMN status ENUM('pending', 'auto_paid', 'manual_pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum (MySQL only, skip for SQLite)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE installments MODIFY COLUMN status ENUM('pending', 'auto_paid', 'manual_pending', 'paid', 'overdue') DEFAULT 'pending'");
        }
    }
};