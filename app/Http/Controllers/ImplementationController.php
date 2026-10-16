<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImplementationRequest;
use App\Http\Requests\UpdateImplementationRequest;
use App\Http\Resources\ImplementationResource;
use App\Models\Implementation;
use App\Traits\SyncsPivotRelations;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ImplementationController extends Controller
{
    use SyncsPivotRelations;

    #[Endpoint(
        title: '列出實作',
        description: '回傳所有實作，依標題排序，每筆含所屬分類與關聯的技術。可用 `type` 查詢參數依類型篩選。',
    )]
    public function index(Request $request)
    {
        $implementations = Implementation::with(['scope', 'techniques'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->orderBy('title')
            ->get();

        return response()->json([
            'type' => Str::of(Implementation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => ImplementationResource::collection($implementations),
        ]);
    }

    #[Endpoint(
        title: '建立實作',
        description: '建立一項實作，並可同時指定關聯的文件與技術。需要登入並有建立權限。',
    )]
    public function store(StoreImplementationRequest $request)
    {
        $this->authorize('create', Implementation::class);

        $data = $request->validated();
        $implementation = Implementation::create(Arr::except($data, ['documentations', 'techniques']));

        if (isset($data['documentations'])) {
            $this->syncPivot($implementation->documentations(), $data['documentations']);
        }
        if (isset($data['techniques'])) {
            $this->syncPivot($implementation->techniques(), $data['techniques']);
        }

        return response()->json([
            'data' => new ImplementationResource($implementation->load(['scope', 'documentations', 'techniques'])),
            'message' => 'Implementation created.',
        ], 201);
    }

    #[Endpoint(
        title: '取得實作',
        description: '回傳單一實作，含所屬分類、關聯的技術，以及關聯的文件（未登入時不含草稿）。',
    )]
    public function show(Implementation $implementation)
    {
        $implementation->load(['scope', 'documentations' => fn ($query) => $query->visibleToViewer(), 'techniques']);

        return response()->json(new ImplementationResource($implementation));
    }

    #[Endpoint(
        title: '更新實作',
        description: '更新實作欄位；有帶文件或技術時一併同步關聯。需要登入並有更新權限。',
    )]
    public function update(UpdateImplementationRequest $request, Implementation $implementation)
    {
        $this->authorize('update', $implementation);

        $data = $request->validated();
        $isUpdated = $implementation->update(Arr::except($data, ['documentations', 'techniques']));

        if (isset($data['documentations'])) {
            $this->syncPivot($implementation->documentations(), $data['documentations']);
        }
        if (isset($data['techniques'])) {
            $this->syncPivot($implementation->techniques(), $data['techniques']);
        }

        return response()->json([
            'data' => new ImplementationResource($implementation->load(['scope', 'documentations', 'techniques'])),
            'message' => $isUpdated
                ? 'Implementation updated.'
                : 'Implementation update failed',
        ]);
    }

    #[Endpoint(
        title: '刪除實作',
        description: '刪除指定的實作。需要登入並有刪除權限。',
    )]
    public function destroy(Implementation $implementation)
    {
        $this->authorize('delete', $implementation);

        $title = $implementation->title;
        $implementation->delete();

        return response()->json([
            'message' => "$title was deleted.",
        ]);
    }
}
