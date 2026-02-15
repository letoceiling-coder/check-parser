<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_notify_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_bot_id');
            $table->unsignedBigInteger('raffle_id');
            $table->unsignedBigInteger('bot_user_id');
            $table->timestamps();

            $table->unique(['telegram_bot_id', 'raffle_id', 'bot_user_id']);
            $table->foreign('telegram_bot_id')->references('id')->on('telegram_bots')->onDelete('cascade');
            $table->foreign('raffle_id')->references('id')->on('raffles')->onDelete('cascade');
            $table->foreign('bot_user_id')->references('id')->on('bot_users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_notify_subscriptions');
    }
};
