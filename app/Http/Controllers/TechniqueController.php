<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTechniqueRequest;
use App\Http\Requests\UpdateTechniqueRequest;
use App\Http\Resources\TechniqueResource;
use App\Models\Technique;
use App\Traits\SyncsPivotRelations;
use Dedoc\Scramble\Attributes\Endpoint;
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
        description: '回傳單一技術，含所屬分類、關聯的實作，以及關聯的文件（未登入時不含草稿）。',
    )]
    public function show(Technique $technique)
    {
        $technique->load(['scope', 'documentations' => fn ($query) => $query->visibleToViewer(), 'implementations']);

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
