<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Изменяем кодировку таблицы checks на utf8mb4 для поддержки Unicode
        DB::statement('ALTER TABLE checks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Возвращаем обратно (не рекомендуется)
        DB::statement('ALTER TABLE checks CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
    }
};
