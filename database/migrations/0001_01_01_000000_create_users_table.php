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
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('使用者ID');
            $table->string('name')->comment('使用者名稱');
            $table->string('email')->unique()->comment('使用者電子郵件');
            $table->timestamp('email_verified_at')->nullable()->comment('電子郵件驗證時間');
            $table->string('password')->comment('密碼雜湊');
            $table->rememberToken()->comment('登入記住Token');
            $table->timestamps();
            $table->comment('系統使用者資料');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('申請密碼重設的電子郵件');
            $table->string('token')->comment('密碼重設Token');
            $table->timestamp('created_at')->nullable()->comment('Token建立時間');
            $table->comment('密碼重設Token記錄');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('SessionID');
            $table->foreignId('user_id')->nullable()->index()->comment('關聯使用者ID');
            $table->string('ip_address', 45)->nullable()->comment('登入IP位址');
            $table->text('user_agent')->nullable()->comment('使用者瀏覽器資訊');
            $table->longText('payload')->comment('Session資料');
            $table->integer('last_activity')->index()->comment('最後活動時間');
            $table->comment('使用者Session記錄');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
