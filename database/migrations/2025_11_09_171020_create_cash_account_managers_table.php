<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_account_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->onDelete('cascade');
            $table->date('assigned_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes
            $table->index('manager_id');
            $table->index('cash_account_id');
            $table->unique(['manager_id', 'cash_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_account_managers');
    }
};