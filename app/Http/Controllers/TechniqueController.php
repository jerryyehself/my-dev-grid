<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeprecateTechniqueRequest;
use App\Http\Requests\StoreTechniqueRequest;
use App\Http\Requests\UpdateTechniqueRequest;
use App\Http\Resources\TechniqueResource;
use App\Models\Technique;
use App\Services\TechniqueMerger;
use App\Traits\SyncsPivotRelations;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TechniqueController extends Controller
{
    use SyncsPivotRelations;

    #[Endpoint(
        title: '列出技術',
        description: '回傳所有技術，依標題排序，每筆含所屬分類。',
    )]
    public function index()
    {
        $techniques = Technique::with('scope')->orderBy('title')->get();

        return response()->json([
            'type' => Str::of(Technique::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => TechniqueResource::collection($techniques),
        ]);
    }

    #[Endpoint(
        title: '建立技術',
        description: '建立一項技術，並可同時指定關聯的文件與實作。需要登入並有建立權限。',
    )]
    public function store(StoreTechniqueRequest $request)
    {
        $this->authorize('create', Technique::class);

        $data = $request->validated();
        $technique = Technique::create(Arr::except($data, ['documentations', 'implementations']));

        if (isset($data['documentations'])) {
            $this->syncPivot($technique->documentations(), $data['documentations']);
        }
        if (isset($data['implementations'])) {
            $this->syncPivot($technique->implementations(), $data['implementations']);
        }

        return response()->json([
            'data' => new TechniqueResource($technique->load(['scope', 'documentations', 'implementations'])),
            'message' => 'Technique created.',
        ], 201);
    }

    #[Endpoint(
        title: '取得技術',
        description: '回傳單一技術，含所屬分類、關聯的實作（未登入時不含設為不公開的），以及關聯的文件（未登入時不含草稿）。',
    )]
    public function show(Technique $technique)
    {
        $technique->load([
            'scope',
            'documentations' => fn ($query) => $query->visibleToViewer(),
            'implementations' => fn ($query) => $query->visibleToViewer(),
        ]);

        return response()->json(new TechniqueResource($technique));
    }

    #[Endpoint(
        title: '更新技術',
        description: '更新技術欄位；有帶文件或實作時一併同步關聯。需要登入並有更新權限。',
    )]
    public function update(UpdateTechniqueRequest $request, Technique $technique)
    {
        $this->authorize('update', $technique);

        $data = $request->validated();
        $isUpdated = $technique->update(Arr::except($data, ['documentations', 'implementations']));

        if (isset($data['documentations'])) {
            $this->syncPivot($technique->documentations(), $data['documentations']);
        }
        if (isset($data['implementations'])) {
            $this->syncPivot($technique->implementations(), $data['implementations']);
        }

        return response()->json([
            'data' => new TechniqueResource($technique->load(['scope', 'documentations', 'implementations'])),
            'message' => $isUpdated
                ? 'Technique updated.'
                : 'Technique update failed',
        ]);
    }

    // 獨立端點，不併進 update()：那條要求整份表單，而且合併會搬動其他資料的連結，跟改欄位是兩回事。
    #[Endpoint(
        title: '停用、合併或取消停用技術',
        description: '把技術標為停用（`deprecated` 為 true），或取消停用（`deprecated` 為 false，改用對象一併清除）。停用時可以用 `replaced_by_id` 指定改用的技術，等於把這個技術合併進去：這個技術的正式名變成改用對象的隱藏別名，連到文件、實作與其他技術的連結都改接到改用對象，改接後跟改用對象既有的連結重複的會刪掉，留下的那一條保留兩者中較早的加入時間（圖譜邊的 `added_at`）。下列情況整個不做，回 422：同一個文件或實作，兩個技術用的述詞不同；改用對象的分類不符合某條連結的述詞；合併後上下位會繞成環、標了相關的兩個技術會有上下位路徑，或上位重複。只停用、不指定改用對象時，連結都不動。停用的技術不會被刪除，id 照舊；同步與匯入文章之後改連到改用對象，沒有改用對象的不再新增或更新連結。取消停用不會把合併時搬走的連結搬回來。重送同樣的內容回 200，停用時間維持第一次停用的時間。需要登入並有更新權限。',
    )]
    public function deprecate(DeprecateTechniqueRequest $request, Technique $technique, TechniqueMerger $merger): JsonResponse
    {
        $this->authorize('deprecate', $technique);

        $validatedData = $request->validated();

        if (! $validatedData['deprecated']) {
            $technique->undeprecate();
        } elseif (($validatedData['replaced_by_id'] ?? null) === null) {
            $technique->deprecate(null);
        } else {
            $merger->merge($technique, Technique::findOrFail($validatedData['replaced_by_id']));
        }

        return response()->json([
            'data' => new TechniqueResource($technique->fresh()->load('scope')),
            'message' => 'Technique updated.',
        ]);
    }

    #[Endpoint(
        title: '刪除技術',
        description: '刪除指定的技術。需要登入並有刪除權限。',
    )]
    public function destroy(Technique $technique)
    {
        $this->authorize('delete', $technique);

        // 用顯示名稱：只說「Vue was deleted.」分不出刪的是 Vue 還是 Vue 3
        $label = $technique->label;
        $technique->delete();

        return response()->json([
            'message' => "$label was deleted.",
        ]);
    }
}
