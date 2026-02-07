<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->string('bank_code', 32)->nullable()->after('currency')
                ->comment('Код банка (sber, tinkoff, alfabank и т.д.) для расширения логики парсинга');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropColumn('bank_code');
        });
    }
};
