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
        Schema::table('bot_settings', function (Blueprint $table) {
            // Новые сообщения для системы Orders
            $table->text('msg_welcome_new')->nullable()->after('msg_support');
            $table->text('msg_welcome_returning')->nullable()->after('msg_welcome_new');
            $table->text('msg_sold_out_with_tickets')->nullable()->after('msg_welcome_returning');
            $table->text('msg_sold_out_no_tickets')->nullable()->after('msg_sold_out_with_tickets');
            $table->text('msg_ask_quantity')->nullable()->after('msg_sold_out_no_tickets');
            $table->text('msg_confirm_order')->nullable()->after('msg_ask_quantity');
            $table->text('msg_order_reserved')->nullable()->after('msg_confirm_order');
            $table->text('msg_payment_instructions')->nullable()->after('msg_order_reserved');
            $table->text('msg_order_approved')->nullable()->after('msg_payment_instructions');
            $table->text('msg_order_rejected')->nullable()->after('msg_order_approved');
            $table->text('msg_order_expired')->nullable()->after('msg_order_rejected');
            $table->text('msg_insufficient_slots')->nullable()->after('msg_order_expired');
            
            // Google Sheets URL
            $table->string('google_sheet_url')->nullable()->after('msg_insufficient_slots');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'msg_welcome_new',
                'msg_welcome_returning',
                'msg_sold_out_with_tickets',
                'msg_sold_out_no_tickets',
                'msg_ask_quantity',
                'msg_confirm_order',
                'msg_order_reserved',
                'msg_payment_instructions',
                'msg_order_approved',
                'msg_order_rejected',
                'msg_order_expired',
                'msg_insufficient_slots',
                'google_sheet_url',
            ]);
        });
    }
};
