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
        Schema::create('relations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_id')->constrained('scopes')->comment('主語id');
            $table->foreignId('object_id')->constrained('scopes')->comment('客語id');

            $table->foreignId('parent_class')
                ->nullable()
                ->constrained('relations')
                ->onDelete('set null')
                ->comment('父類');

            $table->string('class_number', length: 2)->comment('關係類號');
            $table->string('call_number', length: 2)->default('00')->comment('關係子類號');
            $table->string('name', length: 50)->comment('關係名稱');
            $table->text('note')->comment('註釋')->nullable();

            $table->foreignId('reverse_id')->nullable()->constrained('relations')->comment('反向關係');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relations');
    }
};
