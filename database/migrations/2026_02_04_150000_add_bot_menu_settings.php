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
            // Контакт поддержки
            $table->string('support_contact', 255)->nullable()->after('payment_description');
            
            // Информация о розыгрыше
            $table->text('raffle_info')->nullable()->after('support_contact');
            $table->string('prize_description', 500)->nullable()->after('raffle_info');
            
            // Сообщения меню
            $table->text('msg_about_raffle')->nullable()->after('msg_admin_request_rejected');
            $table->text('msg_my_tickets')->nullable()->after('msg_about_raffle');
            $table->text('msg_no_tickets')->nullable()->after('msg_my_tickets');
            $table->text('msg_support')->nullable()->after('msg_no_tickets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'support_contact',
                'raffle_info',
                'prize_description',
                'msg_about_raffle',
                'msg_my_tickets',
                'msg_no_tickets',
                'msg_support',
            ]);
        });
    }
};
