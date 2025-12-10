<?php

declare(strict_types=1);

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
        Schema::table('videos', function (Blueprint $table) {
            $table->string('file_version', 10)
                  ->nullable()
                  ->after('nas_path')
                  ->comment('檔案版本號 (例如: _0, _1, _2)');
            $table->index('file_version', 'idx_file_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex('idx_file_version');
            $table->dropColumn('file_version');
        });
    }
};

