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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id()->comment('租戶ID');
            $table->string('name')->comment('租戶名稱');
            $table->string('domain')->unique()->comment('租戶域名');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->json('settings')->nullable()->comment('租戶設定');
            $table->timestamps();
            $table->comment('多租戶資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
