<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            if (Schema::hasColumn('pilots', 'reg')) {
                $table->dropColumn('reg');
            }
            if (Schema::hasColumn('pilots', 'glider_brand')) {
                $table->dropColumn('glider_brand');
            }
            if (Schema::hasColumn('pilots', 'glider_model')) {
                $table->dropColumn('glider_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pilots', function (Blueprint $table) {
            $table->string('reg')->nullable();
            $table->string('glider_brand')->nullable();
            $table->string('glider_model')->nullable();
        });
    }
};


