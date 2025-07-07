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
            $table->string('sub_title')->comment('並列名稱');
            $table->string('description')->comment('說明')->nullable();
            $table->text('url')->comment('')->nullable();
            $table->string('git_repo_id')->comment('說明')->nullable();
            $table->integer('git_repo_created_at')->comment('狀態')->nullable();
            $table->timestamp('creation_date')->comment('類型')->nullable();
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
