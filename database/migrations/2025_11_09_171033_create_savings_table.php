<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->onDelete('cascade');
            $table->enum('savings_type', ['principal', 'mandatory', 'voluntary', 'holiday'])
                  ->comment('principal=pokok, mandatory=wajib, voluntary=sukarela, holiday=hari_raya');
            $table->decimal('amount', 15, 2);
            $table->decimal('interest_percentage', 5, 2)->default(0);
            $table->decimal('final_amount', 15, 2)->comment('amount + interest');
            $table->date('transaction_date');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('user_id');
            $table->index('cash_account_id');
            $table->index('savings_type');
            $table->index('transaction_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings');
    }
};