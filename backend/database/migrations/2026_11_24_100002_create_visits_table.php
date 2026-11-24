<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('visited_at');
            $table->unsignedTinyInteger('rating');
            $table->unsignedInteger('total_price')->nullable();
            $table->text('notes')->nullable();
            $table->string('companions')->nullable();
            $table->timestamps();

            $table->index(['place_id', 'visited_at']);
            $table->index(['user_id', 'rating']);
            $table->index(['user_id', 'total_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};

