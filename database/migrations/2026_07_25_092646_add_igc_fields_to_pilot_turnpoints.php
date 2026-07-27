<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilot_turnpoints', function (Blueprint $table) {
            $table->string('source', 10)->default('flarm')->after('validated_at');
            $table->integer('igc_distance_m')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('pilot_turnpoints', function (Blueprint $table) {
            $table->dropColumn(['source', 'igc_distance_m']);
        });
    }
};
