<?php

namespace App\Service;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * access to git
 */
class GitService
{

    private $accessGit;

    public function __construct()
    {
        $this->accessGit = Http::withToken(config('services.github.token'));
    }

    public function get_repos()
    {

        try {
            $repos = $this->accessGit
                ->get('https://api.github.com/user/repos', [
                    'sort' => 'updated',
                    'direction' => 'desc'
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            echo $e;
            exit;
        }

        $data = collect($repos)->where('private', false)
            ->map(function ($repo) {
                return $this->clean_repo_info($repo);
            })
            ->filter();

        return $data;
    }

    private function clean_repo_info($repo = null)
    {

        $work = Arr::only($repo, ['id', 'html_url', 'name', 'languages_url', 'topics']);

        $work = array_merge(
            $work,
            [
                'git_repo_id' => $work['id'],
                'technique' => $work['topics'],
                'title' => $work['name'],
            ]
        );

        return $work;
    }
}
