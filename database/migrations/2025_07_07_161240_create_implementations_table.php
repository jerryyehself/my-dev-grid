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
        Schema::create('implementations', function (Blueprint $table) {
            $table->foreignId('type')->constrained('scopes')->comment('類型');
            $table->string('title')->comment('名稱');
            $table->string('sub_title')->comment('並列名稱')->nullable();
            $table->string('description')->comment('說明')->nullable();
            $table->text('url')->comment('url')->nullable();
            $table->string('git_repo_id')->comment('說明')->nullable();
            $table->boolean('is_visible')->comment('顯示狀態')->default(true);
            $table->boolean('maintain_status')->comment('維護類型')->nullable(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('implementations');
    }
};
