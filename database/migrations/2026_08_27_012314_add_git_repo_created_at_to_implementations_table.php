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
        Schema::table('implementations', function (Blueprint $table) {
            $table->timestamp('git_repo_created_at')->comment('對應 GitHub repo 的建立時間')->nullable()->after('git_repo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->dropColumn('git_repo_created_at');
        });
    }
};
