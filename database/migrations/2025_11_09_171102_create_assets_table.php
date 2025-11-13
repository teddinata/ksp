<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->string('asset_name');
            $table->enum('category', ['land', 'building', 'vehicle', 'equipment', 'inventory']);
            $table->decimal('acquisition_cost', 15, 2);
            $table->date('acquisition_date');
            $table->integer('useful_life_months')->comment('0 for land (no depreciation)');
            $table->decimal('residual_value', 15, 2)->default(0)->comment('Salvage value at end of useful life');
            $table->decimal('depreciation_per_month', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->decimal('book_value', 15, 2)->comment('Acquisition cost - accumulated depreciation');
            $table->date('last_depreciation_date')->nullable()->comment('Last calculated depreciation');
            $table->string('location')->nullable();
            $table->enum('status', ['active', 'damaged', 'sold', 'disposed'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('asset_code');
            $table->index('category');
            $table->index('status');
            $table->index('acquisition_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};