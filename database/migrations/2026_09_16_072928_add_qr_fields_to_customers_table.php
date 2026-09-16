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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('member_code')->nullable()->comment('會員編號');
            $table->string('qr_token')->nullable()->comment('QR掃描用的不透明令牌');
            // 在租戶內確保member_code和qr_token的唯一性
            $table->unique(['tenant_id', 'member_code']);
            $table->unique(['tenant_id', 'qr_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'member_code']);
            $table->dropUnique(['tenant_id', 'qr_token']);
            $table->dropColumn(['member_code', 'qr_token']);
        });
    }
};
