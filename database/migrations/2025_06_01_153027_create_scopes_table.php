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
        Schema::create('scopes', function (Blueprint $table) {
            $table->id();
            $table->char('class_number', length: 2)->comment('類號');
            $table->char('call_number', length: 2)->default('00')->comment('子類號');
            $table->boolean('is_scope_lead')->default(false)->index()->comment('實體主類');
            $table->string('name', length: 50)->comment('類名');
            $table->tinyText('comment')->comment('範圍說明');
            $table->text('note')->comment('註釋')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scopes');
    }
};
