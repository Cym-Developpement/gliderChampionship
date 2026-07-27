<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_pilot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pilot_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['participant_id', 'pilot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_pilot');
    }
};


