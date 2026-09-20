<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id()->comment('冪等性鍵ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->string('idempotency_key')->comment('客戶端提供的冪等性鍵');
            $table->string('request_hash')->comment('請求體雜湊，用於驗證請求一致性');
            $table->string('status')->comment('處理狀態：processing, completed, failed');
            $table->longText('response_body')->nullable()->comment('儲存的回應內容');
            $table->integer('response_status')->nullable()->comment('HTTP狀態碼');
            $table->timestamp('started_at')->comment('開始處理時間，用於偵測過期的processing狀態');
            $table->timestamps();

            // 確保同一租戶下冪等性鍵唯一
            $table->unique(['tenant_id', 'idempotency_key'])->comment('租戶內冪等性鍵唯一約束');
            $table->index(['tenant_id', 'status', 'started_at'])->comment('查詢索引');
            $table->comment('資料庫支援的冪等性鍵記錄表，確保交易可靠性');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
