<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 本機或一般主機（有 cron 每分鐘跑 `schedule:run`）才會觸發這條。正式環境在 Cloud Run 上沒有 cron，
// 每天的同步改由 Cloud Scheduler 觸發 Cloud Run Job 跑同一個指令（docs/deployment-gcp.md 第 10 步），
// 這裡的 daily() 在正式環境不會生效，要改正式環境的同步時間去改 Cloud Scheduler
Schedule::command('github:sync-repos')->daily();
