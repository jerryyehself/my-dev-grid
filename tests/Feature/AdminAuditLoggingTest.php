<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;

/**
 * 後台資料變更的稽核紀錄（authz_admin，App\Logging\AdminAudit）。
 */
class AdminAuditLoggingTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    #[TestDox('新增資料時，稽核日誌記錄誰建立了哪筆資料')]
    public function test_creating_a_record_logs_who_created_which_record()
    {
        $user = $this->actingAsOwner();
        $parent = Scope::factory()->create(['class_number' => '99', 'call_number' => '00', 'parent_class' => null]);
        $this->captureLogs();

        $id = $this->postJson('/api/scopes', [
            'name' => 'Audited',
            'parent_class' => $parent->id,
            'call_number' => '10',
            'comment' => 'audit test',
        ])->assertCreated()->json('data.id');

        $this->assertSecurityEvent('authz_admin', [
            'user_id' => $user->id,
            'action' => 'create',
            'object' => 'scope',
            'object_id' => $id,
        ], 'warning');
    }

    #[TestDox('更新資料時只記錄變動的欄位名稱，不記值')]
    public function test_updating_a_record_logs_the_changed_field_names_but_not_their_values()
    {
        $user = $this->actingAsOwner();
        $documentation = Documentation::factory()->create(['title' => 'Old title']);
        $this->captureLogs();

        $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => 'Secret-ish new title',
            'body' => str_repeat('很長的內文', 2000),
        ])->assertOk();

        $context = $this->assertSecurityEvent('authz_admin', [
            'user_id' => $user->id,
            'action' => 'update',
            'object' => 'documentation',
            'object_id' => $documentation->id,
        ]);
        $this->assertEqualsCanonicalizing(['title', 'body'], $context['fields']);
        $this->assertNotLogged('Secret-ish new title');
        $this->assertNotLogged('很長的內文');
    }

    #[TestDox('沒有實際變動的更新不會寫稽核日誌')]
    public function test_an_update_that_changes_nothing_is_not_logged()
    {
        $this->actingAsOwner();
        $technique = Technique::factory()->create();
        $this->captureLogs();

        $this->putJson("/api/techniques/{$technique->id}", [
            'type' => $technique->type,
            'title' => $technique->title,
        ])->assertOk();

        $this->assertNoSecurityEvent('authz_admin');
    }

    #[TestDox('刪除 scope 時連同被刪的關係一併記錄')]
    public function test_deleting_a_scope_logs_the_scope_and_the_relations_deleted_with_it()
    {
        $user = $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $relation = Relation::factory()->create(['subject_id' => $scope->id]);
        $this->captureLogs();

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $deleted = collect($this->securityEvents('authz_admin'))->map(fn ($m) => [$m->context['object'], $m->context['object_id'], $m->context['action'], $m->context['user_id']]);
        $this->assertEqualsCanonicalizing([
            ['scope', $scope->id, 'delete', $user->id],
            ['relation', $relation->id, 'delete', $user->id],
        ], $deleted->all());
    }

    #[TestDox('同步關聯時記錄新增與移除的 id')]
    public function test_pivot_sync_logs_attached_and_detached_ids()
    {
        $user = $this->actingAsOwner();
        $implementation = Implementation::factory()->create();
        [$kept, $removed, $added] = Technique::factory()->count(3)->create()->all();
        $relation = Relation::factory()->create();
        $implementation->techniques()->sync([
            $kept->id => ['relation_id' => $relation->id],
            $removed->id => ['relation_id' => $relation->id],
        ]);
        $this->captureLogs();

        $this->putJson("/api/implementations/{$implementation->id}", [
            'type' => $implementation->type,
            'title' => $implementation->title,
            'techniques' => [
                ['id' => $kept->id, 'relation_id' => $relation->id],
                ['id' => $added->id, 'relation_id' => $relation->id],
            ],
        ])->assertOk();

        $this->assertSecurityEvent('authz_admin', [
            'user_id' => $user->id,
            'action' => 'sync',
            'object' => 'implementation',
            'object_id' => $implementation->id,
            'relation' => 'techniques',
            'attached' => [$added->id],
            'detached' => [$removed->id],
            // sync() 對既有的關聯一律重寫 pivot 欄位，值沒變也算在 updated 裡
            'updated' => [$kept->id],
        ]);
    }

    #[TestDox('沒有登入使用者的寫入不記稽核日誌')]
    public function test_writes_without_a_logged_in_user_are_not_audited()
    {
        // seeder、artisan 指令（snapshot:github-repos）沒有登入使用者
        $this->captureLogs();

        $scope = Scope::factory()->create();
        $scope->update(['name' => 'changed by a command']);
        $scope->delete();

        $this->assertNoSecurityEvent('authz_admin');
    }
}
