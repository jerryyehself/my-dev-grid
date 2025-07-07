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
        Schema::create('documentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type')->constrained('scopes')->comment('類型');
            $table->string('title')->comment('名稱');
            $table->text('url')->comment('')->nullable();
            $table->text('uri')->comment('')->nullable();
            $table->text('note')->comment('說明')->nullable();
            $table->integer('status')->comment('狀態')->nullable();
            $table->timestamp('creation_date')->comment('類型')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentations');
    }
};
