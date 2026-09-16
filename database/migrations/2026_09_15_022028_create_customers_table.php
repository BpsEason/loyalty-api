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
        Schema::create('customers', function (Blueprint $table) {
            $table->id()->comment('客戶ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->string('name')->comment('客戶名稱');
            $table->string('email')->unique()->comment('客戶電子郵件');
            $table->string('phone')->nullable()->comment('客戶電話');
            $table->json('metadata')->nullable()->comment('額外元資料');
            $table->timestamps();

            $table->unique(['tenant_id', 'email'])->comment('同一租戶內郵件唯一');
            $table->comment('客戶資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
