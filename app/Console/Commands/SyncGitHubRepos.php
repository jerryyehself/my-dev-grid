<?php

namespace App\Console\Commands;

use App\Services\SaveReposDataService;
use Illuminate\Console\Command;

class SyncGitHubRepos extends Command
{
    protected $signature = 'github:sync-repos';

    protected $description = 'Pull public GitHub repos and sync them into Implementation/Technique records';

    public function handle(SaveReposDataService $service): int
    {
        $service->save_repos_data();

        $this->info('GitHub repos synced.');

        return self::SUCCESS;
    }
}
