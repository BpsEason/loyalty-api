<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            // 將remaining_points改為unsigned，MySQL會自動保證>=0
            $table->unsignedInteger('remaining_points')->change();
        });
    }

    public function down(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            $table->integer('remaining_points')->change();
        });
    }
};
