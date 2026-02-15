<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('bot_settings', 'msg_reservation_cancelled')) {
                $table->text('msg_reservation_cancelled')->nullable()->after('msg_order_expired');
            }
            if (!Schema::hasColumn('bot_settings', 'msg_slots_available')) {
                $table->text('msg_slots_available')->nullable()->after('msg_reservation_cancelled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['msg_reservation_cancelled', 'msg_slots_available']);
        });
    }
};
