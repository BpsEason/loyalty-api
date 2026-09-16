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
        Schema::create('point_accounts', function (Blueprint $table) {
            $table->id()->comment('點數帳戶ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('關聯客戶ID');
            $table->integer('balance')->default(0)->comment('目前點數餘額');
            $table->integer('total_earned')->default(0)->comment('累計獲得點數');
            $table->integer('total_redeemed')->default(0)->comment('累計使用點數');
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id'])->comment('同一租戶內每個客戶僅有一個點數帳戶');
            $table->comment('客戶點數帳戶資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_accounts');
    }
};
