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
        // point_transactions 表格新增效能索引
        Schema::table('point_transactions', function (Blueprint $table) {
            // 支援客戶交易歷史查詢：(tenant_id, customer_id, created_at)
            $table->index(['tenant_id', 'customer_id', 'created_at'], 'pt_tenant_customer_created_idx');
            // 支援儀表板點數趨勢查詢：(tenant_id, type, created_at)
            $table->index(['tenant_id', 'type', 'created_at'], 'pt_tenant_type_created_idx');
        });

        // customers 表格新增效能索引
        Schema::table('customers', function (Blueprint $table) {
            // 支援客戶增長趨勢查詢：(tenant_id, created_at)
            $table->index(['tenant_id', 'created_at'], 'cust_tenant_created_idx');
        });

        // reward_grants 表格新增效能索引
        Schema::table('reward_grants', function (Blueprint $table) {
            // 支援獎勵概況查詢：(tenant_id, status, created_at)
            $table->index(['tenant_id', 'status', 'created_at'], 'rg_tenant_status_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropIndex('pt_tenant_customer_created_idx');
            $table->dropIndex('pt_tenant_type_created_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('cust_tenant_created_idx');
        });

        Schema::table('reward_grants', function (Blueprint $table) {
            $table->dropIndex('rg_tenant_status_created_idx');
        });
    }
};