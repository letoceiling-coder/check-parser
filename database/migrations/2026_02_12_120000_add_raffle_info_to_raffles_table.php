<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->text('raffle_info')->nullable()->after('slots_mode');
            $table->string('prize_description', 500)->nullable()->after('raffle_info');
        });
    }

    public function down(): void
    {
        Schema::table('raffles', function (Blueprint $table) {
            $table->dropColumn(['raffle_info', 'prize_description']);
        });
    }
};
