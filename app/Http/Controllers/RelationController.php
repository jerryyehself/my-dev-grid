<?php

namespace App\Http\Controllers;

use App\Exceptions\RelationLockedException;
use App\Http\Requests\StoreRelationRequest;
use App\Http\Requests\UpdateRelationRequest;
use App\Http\Resources\RelationResource;
use App\Models\Relation;
use App\Models\Scope;
use App\Service\RelationEdgeQuery;
use Illuminate\Support\Str;

class RelationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // withReferenceCounts() 是必要的,不是最佳化:RelationResource 會吐
        // is_referenced/referenced_via/locked_fields,沒有預載的話每一列都會各自
        // 去查 8 次連結表。實測不預載 344 次查詢,預載後 20 次。
        $relationList = Relation::with(['parent', 'subject', 'object'])
            ->withReferenceCounts()
            ->orderBy('class_number')
            ->orderBy('call_number')
            ->get();

        return response()->json([
            'type' => Str::of(Relation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => RelationResource::collection($relationList),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $scopeOptions = Scope::select('id', 'class_number', 'call_number', 'name')
            ->orderBy('class_number')
            ->distinct()->get();

        return response()->json([
            'id' => [
                'required' => false,
                'type' => 'hidden',
            ],
            'name' => [
                'label' => '名稱',
                'required' => true,
                'type' => 'text',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4',
                ],
            ],
            'subject_id' => [
                'label' => '主體',
                'required' => true,
                'type' => 'select',
                'options' => $scopeOptions,
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4',
                ],
            ],
            'object_id' => [
                'label' => '客體',
                'required' => true,
                'type' => 'select',
                'options' => $scopeOptions,
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4',
                ],
            ],
            'class_number' => [
                'label' => '類號',
                'required' => true,
                'type' => 'label',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-6',
                ],
            ],
            'call_number' => [
                'label' => '子類號',
                'required' => true,
                'type' => 'number',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-6',
                ],
            ],
            'note' => [
                'label' => '註釋',
                'required' => false,
                'type' => 'textarea',
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRelationRequest $request)
    {
        $this->authorize('create', Relation::class);

        $validatedData = $request->validated();

        if ($validatedData['call_number'] != '00') {
            $validatedData['parent_class'] = Relation::where('class_number', $validatedData['class_number'])
                ->where('call_number', '00')
                ->value('id');
        }

        $relation = Relation::firstOrCreate(
            ['name' => $validatedData['name']],
            $validatedData
        );

        return response()->json([
            'data' => new RelationResource($relation->loadCount(Relation::LINK_RELATIONS)->load(['parent', 'children', 'reverse' => fn ($q) => $q->withCount(Relation::LINK_RELATIONS)])),
            'message' => $relation->wasRecentlyCreated
                ? 'Relation created.'
                : 'Relation already exists.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Relation $relation)
    {
        $relation->load(['parent', 'children']);

        // own_edges_count / reverse_edges_count / is_referenced 都靠這組計數。
        // 不預載的話它們會各自去查連結表,而且反向那條還要再查一輪。
        $relation->loadCount(Relation::LINK_RELATIONS)
            ->load(['reverse' => fn ($query) => $query->withCount(Relation::LINK_RELATIONS)]);

        // withFormHints()：只有這支端點算 new_child_call_number。Triple 的
        // fetchCallNumberByClass() 打的就是這裡。清單頁不開，那是 N+1 的來源
        // （見 RelationResource 的註解）。
        return response()->json(
            (new RelationResource($relation))->withFormHints()
        );
    }

    /**
     * 使用這個述詞的邊,分頁(規格 B5)。
     *
     * 為什麼是獨立端點而不是塞進 show:`uses` 一條就有 84 筆邊,而詳情頁要的是可以
     * 翻頁的清單。塞進 show 等於每次開詳情頁都把全部邊撈出來,也讓 show 的回應
     * 大小隨資料成長。正規化與 UNION 的細節見 RelationEdgeQuery。
     *
     * 跟 index/show 同一種公開等級——圖譜資料本來就是公開唯讀的。
     */
    public function edges(Relation $relation)
    {
        $edges = (new RelationEdgeQuery($relation))
            ->paginate(request()->integer('per_page') ?: null);

        return response()->json($edges);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Relation $relation)
    {
        return $this->show($relation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRelationRequest $request, Relation $relation)
    {
        $this->authorize('update', $relation);

        $validatedData = $request->validated();

        try {
            $isUpdated = $relation->update($validatedData);
        } catch (RelationLockedException $e) {
            return response()->json([
                'errors' => [
                    'locked' => [$e->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => new RelationResource($relation),
            'message' => $isUpdated
                ? 'Relation updated.'
                : 'Relation update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Relation $relation)
    {
        $this->authorize('delete', $relation);

        $relationName = $relation->name;
        $relation->delete();

        return response()->json([
            'message' => "$relationName was deleted.",
        ]);
    }
}
