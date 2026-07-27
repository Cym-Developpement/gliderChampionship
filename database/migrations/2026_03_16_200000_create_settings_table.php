<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value');
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['key', 'competition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
