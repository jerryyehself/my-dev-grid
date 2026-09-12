<?php

namespace Tests\Feature;

use App\Models\EntityRelation;
use App\Models\Implementation;
use Database\Seeders\RepoLineageRelationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RepoLineageRelationsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeImplementation(string $title): Implementation
    {
        return Implementation::factory()->create(['title' => $title]);
    }

    public function test_it_seeds_the_known_real_implementation_relations()
    {
        $this->seed();

        $this->makeImplementation('my-dev-grid');
        $this->makeImplementation('Laravel-LearningLibrary');
        $this->makeImplementation('my-dev-grid-front');
        $this->makeImplementation('marqee-maker');
        $this->makeImplementation('laravel-demo');
        $this->makeImplementation('api-demo');

        (new RepoLineageRelationsSeeder)->setContainer($this->app)->run();

        $devGrid = Implementation::where('title', 'my-dev-grid')->firstOrFail();
        $learningLibrary = Implementation::where('title', 'Laravel-LearningLibrary')->firstOrFail();
        $devGridFront = Implementation::where('title', 'my-dev-grid-front')->firstOrFail();
        $marqeeMaker = Implementation::where('title', 'marqee-maker')->firstOrFail();
        $laravelDemo = Implementation::where('title', 'laravel-demo')->firstOrFail();
        $apiDemo = Implementation::where('title', 'api-demo')->firstOrFail();

        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'implementation',
            'subject_id' => $devGrid->id,
            'object_id' => $learningLibrary->id,
        ]);
        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'implementation',
            'subject_id' => $devGrid->id,
            'object_id' => $devGridFront->id,
        ]);
        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'implementation',
            'subject_id' => $marqeeMaker->id,
            'object_id' => $learningLibrary->id,
        ]);
        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'implementation',
            'subject_id' => $learningLibrary->id,
            'object_id' => $laravelDemo->id,
        ]);
        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'implementation',
            'subject_id' => $laravelDemo->id,
            'object_id' => $apiDemo->id,
        ]);

        // 不重複執行的安全網：同一筆事實再跑一次不會插入重複列。
        (new RepoLineageRelationsSeeder)->setContainer($this->app)->run();
        $this->assertEquals(1, EntityRelation::where([
            'subject_id' => $devGrid->id,
            'object_id' => $learningLibrary->id,
        ])->count());
    }

    public function test_it_throws_a_clear_error_when_a_repo_is_missing()
    {
        $this->seed();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('my-dev-grid');

        (new RepoLineageRelationsSeeder)->setContainer($this->app)->run();
    }
}
