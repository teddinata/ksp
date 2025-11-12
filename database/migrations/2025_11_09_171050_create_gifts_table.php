<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('gift_type', ['holiday', 'achievement', 'birthday', 'special_event', 'loyalty']);
            $table->string('gift_name');
            $table->decimal('gift_value', 15, 2)->comment('Gift value in rupiah');
            $table->date('distribution_date');
            $table->enum('status', ['pending', 'distributed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('distributed_by')->nullable()->constrained('users');
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('gift_type');
            $table->index('status');
            $table->index('distribution_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};