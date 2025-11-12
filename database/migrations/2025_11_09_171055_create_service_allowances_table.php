<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('period_month')->comment('1-12');
            $table->integer('period_year')->comment('YYYY');
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('savings_bonus', 15, 2)->default(0);
            $table->decimal('loan_bonus', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('distributed_by')->nullable()->constrained('users');
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index(['period_month', 'period_year']);
            $table->index('status');
            $table->unique(['user_id', 'period_month', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_allowances');
    }
};