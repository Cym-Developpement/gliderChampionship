<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            $table->string('reg')->nullable()->after('callsign');
            $table->string('glider_brand')->nullable()->after('reg');
            $table->string('glider_model')->nullable()->after('glider_brand');
        });
    }

    public function down(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            $table->dropColumn(['reg', 'glider_brand', 'glider_model']);
        });
    }
};


