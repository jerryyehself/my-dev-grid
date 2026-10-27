<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRelationRequest;
use App\Http\Requests\UpdateRelationRequest;
use App\Http\Resources\RelationResource;
use App\Models\Relation;
use App\Models\Scope;
use App\Services\RelationEdgeQuery;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Str;

class RelationController extends Controller
{
    #[Endpoint(
        title: '列出述詞',
        description: '回傳所有述詞，依類號、子類號排序，每筆含父層、主體與客體分類，以及被引用的狀態。',
    )]
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

    #[Endpoint(
        title: '取得述詞表單欄位',
        description: '回傳新增述詞表單的欄位定義（標籤、型別、是否必填）；主體與客體欄位的選項是所有分類。',
    )]
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

    #[Endpoint(
        title: '建立述詞',
        description: '建立一條述詞；同名述詞已存在時不重複建立，回傳既有的那一筆。需要登入並有建立權限。',
    )]
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

    #[Endpoint(
        title: '取得述詞',
        description: '回傳單一述詞，含父層、子述詞、反向述詞，以及它被使用的邊數與是否被引用。另附建立子述詞時可用的下一個子類號。',
    )]
    public function show(Relation $relation)
    {
        $relation->load(['parent', 'children']);

        // own_edges_count / reverse_edges_count / is_referenced 都靠這組計數。
        // 不預載的話它們會各自去查連結表,而且反向那條還要再查一輪。
        $relation->loadCount(Relation::LINK_RELATIONS)
            ->load(['reverse' => fn ($query) => $query->withCount(Relation::LINK_RELATIONS)]);

        // withFormHints()：只有這支端點算 new_child_call_number（預填新增表單的
        // 子類號用）。清單頁不開，那是 N+1 的來源（見 RelationResource 的註解）。
        return response()->json(
            (new RelationResource($relation))->withFormHints()
        );
    }

    /**
     * 使用這個述詞的邊，分頁。
     *
     * 為什麼是獨立端點而不是塞進 show：一條述詞可能有很多筆邊，而詳情頁要的是可以
     * 翻頁的清單。塞進 show 等於每次開詳情頁都把全部邊撈出來，也讓 show 的回應
     * 大小隨資料成長。正規化與 UNION 的細節見 RelationEdgeQuery。
     *
     * 跟 index/show 同一種公開等級——圖譜資料本來就是公開唯讀的。
     */
    // 分頁參數由 paginate() 自己讀，邊的欄位來自 RelationEdgeQuery 的 UNION 查詢，
    // Scramble 都推不出來，在這裡寫明給 OpenAPI 文件用。
    #[Endpoint(
        title: '列出述詞使用的邊',
        description: '分頁列出使用這條述詞的邊（文件、技術、實作之間的連結），每筆含兩端的類型、id 與標題。公開唯讀，不需登入。',
    )]
    #[QueryParameter('page', '頁碼，從 1 開始。', type: 'int', default: 1)]
    #[QueryParameter('per_page', '每頁筆數，預設 '.RelationEdgeQuery::DEFAULT_PER_PAGE.'，最多 '.RelationEdgeQuery::MAX_PER_PAGE.'。', type: 'int', default: RelationEdgeQuery::DEFAULT_PER_PAGE)]
    #[Response(200, 'Laravel 的 length-aware 分頁格式。', type: 'array{current_page: int, data: list<array{subject_type: "documentation"|"technique"|"implementation", subject_id: int, subject_title: string, object_type: "documentation"|"technique"|"implementation", object_id: int, object_title: string, source: string}>, first_page_url: string, from: int|null, last_page: int, last_page_url: string, links: list<array{url: string|null, label: string, active: bool}>, next_page_url: string|null, path: string, per_page: int, prev_page_url: string|null, to: int|null, total: int}')]
    public function edges(Relation $relation)
    {
        $edges = (new RelationEdgeQuery($relation))
            ->paginate(request()->integer('per_page') ?: null);

        return response()->json($edges);
    }

    public function edit(Relation $relation)
    {
        return $this->show($relation);
    }

    #[Endpoint(
        title: '更新述詞',
        description: '更新述詞欄位。已被引用的述詞，被鎖住的欄位不能修改，違反時回 422。需要登入並有更新權限。',
    )]
    public function update(UpdateRelationRequest $request, Relation $relation)
    {
        $this->authorize('update', $relation);

        $validatedData = $request->validated();

        // RelationLockedException 不在這裡攔。它自己有 render()，會回一個
        // 以「被鎖住的欄位名」為 key 的 422——原本這裡攔下來組的 `errors.locked`
        // 對不上表單任何一個輸入欄位的 key，訊息因此渲染不到畫面上。
        // 詳見 App\Exceptions\RelationLockedException 的註解。
        $isUpdated = $relation->update($validatedData);

        return response()->json([
            'data' => new RelationResource($relation),
            'message' => $isUpdated
                ? 'Relation updated.'
                : 'Relation update failed',
        ]);
    }

    #[Endpoint(
        title: '刪除述詞',
        description: '刪除指定的述詞。需要登入並有刪除權限。',
    )]
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
