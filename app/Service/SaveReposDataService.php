<?php

namespace App\Service;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use RuntimeException;

/**
 * save git data
 *
 * Pulls every public repo from GitService, upserts each one as an
 * Implementation (type: project), and links it to a Technique per
 * language/topic via the existing `uses` Relation.
 */
class SaveReposDataService
{
    public $gitService;

    /**
     * @var array<string, int>
     */
    private array $scopeIdCache = [];

    public function __construct()
    {
        $gitService = new GitService;
        $this->gitService = $gitService->get_repos();
    }

    public function save_repos_data(): void
    {
        $usesRelationId = $this->relation_id('uses');

        $this->gitService->each(function ($repo) use ($usesRelationId) {
            $project = $this->save_repos_content($repo);

            $techniqueIds = collect($repo['languages'] ?? [])
                ->map(fn ($language) => $this->find_or_create_technique($language, 'language'))
                ->concat(
                    collect($repo['topics'] ?? [])
                        ->map(fn ($topic) => $this->find_or_create_technique($topic, 'packagetool'))
                )
                ->unique();

            $project->techniques()->syncWithoutDetaching(
                $techniqueIds->mapWithKeys(fn ($id) => [$id => ['relation_id' => $usesRelationId]])->all()
            );
        });
    }

    public function save_repos_content($repo)
    {
        return Implementation::updateOrCreate(
            ['git_repo_id' => $repo['id']],
            [
                'type' => $this->scope_id('project'),
                'title' => $repo['title'],
                'description' => $repo['description'] ?? null,
                'url' => $repo['html_url'] ?? null,
                'git_repo_created_at' => $repo['created_at'] ?? null,
                // maintain_status 對應「repo 目前是否仍在維護」，跟 GitHub 的
                // archived flag 相反（archived = 不再維護）；語意來源見
                // my-dev-grid-front 舊版 scripts/sync-projects.mjs 的
                // status/statusType 推導邏輯（同一批資料的原始設計）。
                'maintain_status' => ! ($repo['archived'] ?? false),
            ]
        );
    }

    private function find_or_create_technique(string $name, string $scopeName): int
    {
        return Technique::firstOrCreate([
            'type' => $this->scope_id($scopeName),
            'title' => $name,
        ])->id;
    }

    private function scope_id(string $name): int
    {
        return $this->scopeIdCache[$name] ??= Scope::where('name', $name)->value('id')
            ?? throw new RuntimeException("Scope '{$name}' is not seeded; cannot sync GitHub repo data.");
    }

    private function relation_id(string $name): int
    {
        return Relation::where('name', $name)->value('id')
            ?? throw new RuntimeException("Relation '{$name}' is not seeded; cannot sync GitHub repo data.");
    }
}
