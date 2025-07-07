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

    public function getRepos()
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
            $this->sendLineNotify($e);
            exit;
        }

        $data = collect($repos)->where('private', false)
            ->map(function ($repo) {
                return $this->cleanRepoInfo($repo);
            })
            ->filter();

        return $data;
    }

    private function cleanRepoInfo($repo = null)
    {

        $work = Arr::only($repo, ['id', 'html_url', 'name', 'languages_url', 'created_at', 'updated_at', 'topics']);

        $work = array_merge(
            $work,
            [
                'git_repository_id' => $work['id'],
                'repo_updated_at' => Carbon::parse($work['updated_at']),
                'repo_created_at' => Carbon::parse($work['created_at']),
                'languages' => $this->getLanguagesInfo($work['languages_url']),
                'project_name' => $work['name'],
            ]
        );

        return $work;
    }

    private function getLanguagesInfo($repoLangUrl)
    {

        $languages = $this->accessGit
            ->get($repoLangUrl)
            ->throw()
            ->json();

        return (!$languages) ? [] : array_keys($languages);
    }

    protected function sendLineNotify(Throwable $exception)
    {
        // 撈出錯誤狀態碼
        $statusCode = $exception->getCode();

        // 撈出錯誤訊息
        $message = $exception->getMessage();

        // 撈出錯誤網址
        $url = request()->fullUrl();

        $lineMessage = "\n";
        $lineMessage .= "Error {$statusCode}: \n";
        $lineMessage .= "URL: " . $url . "\n";
        $lineMessage .= "須更新權杖\n";
        $lineMessage .= "Message: " . $message . "\n";

        // 利用 GuzzleHttp\Client 呼叫 API
        $client = new Client;

        try {
            $response = $client->post('https://notify-api.line.me/api/notify', [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.line.token'),
                ],
                'form_params' => [
                    'message' => $lineMessage,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                logger()->error('Failed to send LINE Notify message', [
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ]);
            }
        } catch (\Exception $e) {
            logger()->error('Failed to send LINE Notify due to an error', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
