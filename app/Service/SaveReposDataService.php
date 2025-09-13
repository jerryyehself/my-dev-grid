<?php

namespace App\Service;

use App\Models\Implementation;

/**
 * save git data
 */
class SaveReposDataService
{

    public $gitService;

    public function __construct()
    {
        $gitService = new GitService;
        $this->gitService = $gitService->get_repos();
    }

    public function save_repos_data()
    {
        dd($this->gitService);
        $this->gitService->map(function ($repo) {

            $project = $this->save_repos_content($repo);

            collect($repo['languages'])->map(function ($lang) use ($project) {});

            collect($repo['topics'])->map(function ($tag) use ($project) {});
        });

        return response()->json(['status' => 'success'], 201);
    }

    public function save_repos_content($repo)
    {
        return Implementation::updateOrCreate(
            [
                'git_repo_id' => $repo['id']
            ],
            [
                'title' => $repo['title'],
            ]
        );
    }
}
