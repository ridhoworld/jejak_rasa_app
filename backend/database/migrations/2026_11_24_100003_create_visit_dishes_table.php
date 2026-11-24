<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('price')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->index(['visit_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_dishes');
    }
};

