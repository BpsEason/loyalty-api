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
            $table->foreignId('membership_tier_id')->nullable()->constrained()->onDelete('set null')->comment('當前會員等級ID');
            $table->decimal('total_spend', 12, 2)->default(0)->comment('累積消費金額');
            $table->decimal('total_points_earned', 12, 2)->default(0)->comment('累積獲得點數');
            $table->timestamp('tier_updated_at')->nullable()->comment('等級更新時間');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['membership_tier_id']);
            $table->dropColumn(['membership_tier_id', 'total_spend', 'total_points_earned', 'tier_updated_at']);
        });
    }
};
