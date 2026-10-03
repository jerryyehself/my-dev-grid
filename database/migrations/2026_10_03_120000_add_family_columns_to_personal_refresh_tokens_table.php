<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * refresh token 的「家族」：同一次登入（帳密登入或 OAuth 換發）發出的第一支
 * refresh token，跟之後每次換發出來的都屬於同一個 family_id。
 *
 * - family_id：重放已經用掉的 refresh token（被偷的徵兆）時，整個家族一起撤銷。
 * - family_started_at：原始登入時間，換發不會往後延；超過上限（預設 90 天）
 *   就不再換發，要重新登入。
 * - used_at：換發時不再刪掉舊的那支，改成標記用掉的時間，之後被重放才認得出來。
 *
 * 三個欄位都 nullable、沒有回填：migrate Job 會在新版程式接流量之前跑，
 * 舊版程式不讀這幾個欄位，照常運作；新版程式把 family_id 是 null 的舊資料列
 * 當成「自己一個家族、從 created_at 起算」（見 App\Service\RefreshTokenFamilies）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_refresh_tokens', static function (Blueprint $table) {
            $table->uuid('family_id')->nullable()->index();
            $table->timestamp('family_started_at')->nullable();
            $table->timestamp('used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_refresh_tokens', static function (Blueprint $table) {
            $table->dropIndex(['family_id']);
            $table->dropColumn(['family_id', 'family_started_at', 'used_at']);
        });
    }
};
