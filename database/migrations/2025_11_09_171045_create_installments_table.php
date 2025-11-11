<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->integer('installment_number');
            $table->date('due_date');
            $table->date('payment_date')->nullable();
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_principal', 15, 2);
            $table->enum('status', ['pending', 'auto_paid', 'manual_pending', 'paid', 'overdue'])
                  ->default('pending');
            $table->enum('payment_method', ['service_allowance', 'transfer', 'cash'])->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamps();
            
            // Indexes
            $table->index('loan_id');
            $table->index('due_date');
            $table->index('status');
            $table->index('installment_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};