<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_turnpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pilot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('turnpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_day_id')->constrained()->cascadeOnDelete();
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->unique(['pilot_id', 'turnpoint_id', 'competition_day_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_turnpoints');
    }
};
