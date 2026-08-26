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
        Schema::create('entity_relations', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->comment('documentation/technique/implementation,決定 subject_id/object_id 指向哪張表');
            $table->unsignedBigInteger('subject_id')->comment('無 DB 層外鍵,指向的表隨 entity_type 變動,完整性在 Model 層檢查');
            $table->unsignedBigInteger('object_id')->comment('無 DB 層外鍵,指向的表隨 entity_type 變動,完整性在 Model 層檢查');
            $table->foreignId('relation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entity_type', 'subject_id', 'object_id', 'relation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_relations');
    }
};
