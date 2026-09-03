<?php

namespace App\Service;

use App\Models\Documentation;
use App\Models\EntityRelation;
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
 *
 * Two additional, hand-maintained relations are filled in where known:
 * - A framework/library topic (e.g. `vue`) is linked to its single base
 *   language Technique (e.g. `JavaScript`) via `requires` (same-type,
 *   `entity_relations`) — see FRAMEWORK_BASE_LANGUAGE.
 * - A Technique with a known official documentation site gets a
 *   Documentation (type: sourcesite) linked to it via `specs` — see
 *   OFFICIAL_DOCS.
 * Both maps are deliberately small: a Technique not listed just doesn't
 * get the extra edge, rather than guessing at one.
 */
class SaveReposDataService
{
    public $gitService;

    /**
     * @var array<string, int>
     */
    private array $scopeIdCache = [];

    /**
     * GitHub repo `topics` (as GitHub stores them: lowercase) that are a
     * framework/library with one unambiguous base language, mapped to that
     * language exactly as GitHub's languages API names it.
     */
    private const FRAMEWORK_BASE_LANGUAGE = [
        'vue' => 'JavaScript',
        'vuejs' => 'JavaScript',
        'react' => 'JavaScript',
        'nextjs' => 'JavaScript',
        'nuxt' => 'JavaScript',
        'nuxtjs' => 'JavaScript',
        'express' => 'JavaScript',
        'angular' => 'TypeScript',
        'laravel' => 'PHP',
        'symfony' => 'PHP',
        'django' => 'Python',
        'flask' => 'Python',
        'rails' => 'Ruby',
        'spring' => 'Java',
        'spring-boot' => 'Java',
    ];

    /**
     * Official documentation site URL for a Technique, keyed by its title
     * exactly as stored (topics are stored lowercase; languages are stored
     * however GitHub's languages API names them).
     */
    private const OFFICIAL_DOCS = [
        'vue' => 'https://vuejs.org/',
        'vuejs' => 'https://vuejs.org/',
        'react' => 'https://react.dev/',
        'angular' => 'https://angular.dev/',
        'laravel' => 'https://laravel.com/docs',
        'symfony' => 'https://symfony.com/doc/current/index.html',
        'django' => 'https://docs.djangoproject.com/',
        'flask' => 'https://flask.palletsprojects.com/',
        'rails' => 'https://guides.rubyonrails.org/',
        'spring' => 'https://spring.io/docs',
        'PHP' => 'https://www.php.net/docs.php',
        'JavaScript' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript',
        'TypeScript' => 'https://www.typescriptlang.org/docs/',
        'Python' => 'https://docs.python.org/3/',
        'Ruby' => 'https://www.ruby-lang.org/en/documentation/',
        'Java' => 'https://docs.oracle.com/en/java/',
    ];

    public function __construct()
    {
        $gitService = new GitService;
        $this->gitService = $gitService->get_repos();
    }

    public function save_repos_data(): void
    {
        $usesRelationId = $this->relation_id('uses');
        $requiresRelationId = $this->relation_id('requires');
        $specsRelationId = $this->relation_id('specs');

        $this->gitService->each(function ($repo) use ($usesRelationId, $requiresRelationId, $specsRelationId) {
            $project = $this->save_repos_content($repo);

            $techniques = collect($repo['languages'] ?? [])
                ->map(fn ($language) => $this->find_or_create_technique($language, 'language'))
                ->concat(
                    collect($repo['topics'] ?? [])->map(function ($topic) use ($requiresRelationId) {
                        $technique = $this->find_or_create_technique($topic, 'packagetool');
                        $this->link_framework_to_base_language($technique, $topic, $requiresRelationId);

                        return $technique;
                    })
                )
                ->unique('id');

            $project->techniques()->syncWithoutDetaching(
                $techniques->mapWithKeys(fn ($technique) => [$technique->id => ['relation_id' => $usesRelationId]])->all()
            );

            $techniques->each(fn ($technique) => $this->link_official_docs($technique, $specsRelationId));
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

    private function find_or_create_technique(string $name, string $scopeName): Technique
    {
        return Technique::firstOrCreate([
            'type' => $this->scope_id($scopeName),
            'title' => $name,
        ]);
    }

    /**
     * If $topic is a known framework/library with a single base language
     * (FRAMEWORK_BASE_LANGUAGE), find-or-create that language's own
     * Technique and link $framework to it via a same-type `requires`
     * EntityRelation. A no-op for any topic not in the map.
     */
    private function link_framework_to_base_language(Technique $framework, string $topic, int $requiresRelationId): void
    {
        $baseLanguageName = self::FRAMEWORK_BASE_LANGUAGE[$topic] ?? null;

        if (! $baseLanguageName) {
            return;
        }

        $baseLanguage = $this->find_or_create_technique($baseLanguageName, 'language');

        EntityRelation::firstOrCreate([
            'entity_type' => 'technique',
            'subject_id' => $framework->id,
            'object_id' => $baseLanguage->id,
            'relation_id' => $requiresRelationId,
        ]);
    }

    /**
     * If $technique has a known official documentation site (OFFICIAL_DOCS),
     * find-or-create a Documentation (type: sourcesite) for it and link it
     * to $technique via `specs`. A no-op for any Technique not in the map.
     */
    private function link_official_docs(Technique $technique, int $specsRelationId): void
    {
        $url = self::OFFICIAL_DOCS[$technique->title] ?? null;

        if (! $url) {
            return;
        }

        $documentation = Documentation::firstOrCreate(
            ['url' => $url],
            [
                'type' => $this->scope_id('sourcesite'),
                'title' => "{$technique->title} 官方文件",
                'status' => 1,
            ]
        );

        $documentation->techniques()->syncWithoutDetaching([
            $technique->id => ['relation_id' => $specsRelationId],
        ]);
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
