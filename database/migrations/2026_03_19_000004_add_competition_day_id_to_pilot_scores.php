<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilot_scores', function (Blueprint $table) {
            $table->foreignId('competition_day_id')->nullable()->after('pilot_id')
                ->constrained('competition_days')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pilot_scores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('competition_day_id');
        });
    }
};
