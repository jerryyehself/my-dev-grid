<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 跟部署時跑的是同一份預設資料（見 ReferenceDataSeeder），本機和測試才不會跟正式環境走樣。
        $this->call(ReferenceDataSeeder::class);
    }
}
