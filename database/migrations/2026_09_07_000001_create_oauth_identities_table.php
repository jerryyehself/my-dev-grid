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
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->comment("'google' | 'line'，決定這筆綁定來自哪個 OAuth provider");
            $table->string('provider_user_id')->comment('該 provider 給的使用者唯一識別碼');
            // LINE 的 email scope 不保證授權方會同意給，跟 Google 不同，所以允許 null。
            $table->string('provider_email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
