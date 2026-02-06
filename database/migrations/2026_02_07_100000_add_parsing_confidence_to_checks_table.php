<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->float('parsing_confidence')->nullable()->after('readable_ratio');
            $table->boolean('needs_review')->default(false)->after('parsing_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropColumn(['parsing_confidence', 'needs_review']);
        });
    }
};
