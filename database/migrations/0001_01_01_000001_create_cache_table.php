<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('快取鍵名');
            $table->mediumText('value')->comment('快取值');
            $table->integer('expiration')->index()->comment('過期時間戳');
            $table->comment('系統快取資料表');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('鎖定鍵名');
            $table->string('owner')->comment('擁有者識別');
            $table->integer('expiration')->index()->comment('過期時間戳');
            $table->comment('快取鎖定記錄');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
