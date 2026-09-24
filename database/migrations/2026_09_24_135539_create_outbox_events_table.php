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
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id()->comment('Outbox event ID');
            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('所屬租戶 ID');

            $table->string('event_type')->comment('領域事件類型');
            $table->string('aggregate_type')->comment('Aggregate 類型');
            $table->string('aggregate_id')->comment('Aggregate ID');
            $table->uuid('event_id')->unique()->comment('領域事件唯一識別碼');
            $table->json('payload')->comment('事件 payload');
            $table->timestamp('occurred_at')->comment('事件發生時間');
            $table->timestamp('processed_at')->nullable()->comment('事件成功處理時間');
            $table->integer('attempts')->default(0)->comment('事件處理嘗試次數');
            $table->text('last_error')->nullable()->comment('最後一次處理失敗的錯誤訊息');
            $table->timestamps();

            $table->index(['tenant_id', 'processed_at', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
