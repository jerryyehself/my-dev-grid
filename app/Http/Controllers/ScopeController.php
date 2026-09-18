<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScopeRequest;
use App\Http\Requests\UpdateScopeRequest;
use App\Http\Resources\ScopeResource;
use App\Models\Scope;
use Illuminate\Support\Str;

class ScopeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $scopeList = Scope::with('parent')
            ->withDetailCounts()
            ->orderBy('class_number')
            ->orderBy('call_number')
            ->get();

        return response()->json([
            'type' => Str::of(Scope::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => ScopeResource::collection($scopeList),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // 選項是「可以當父層的頂層 Scope」,送出的值是它們的 id——所以欄位名叫
        // parent_class,跟 StoreScopeRequest/UpdateScopeRequest 收的欄位一致。
        $parentOptions = Scope::select('id', 'class_number', 'name')
            ->where('parent_class', null)
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
            ],
            'parent_class' => [
                // 「父類」是這個專案自己的用詞——scopes 資料表 parent_class 欄位的
                // comment 就是它,而這支 create() 的每個 label 都對應欄位註解
                // (類號/子類號/範圍說明/註釋)。不要另外發明一個同義詞。
                'label' => '父類',
                'required' => true,
                'type' => 'select',
                'options' => $parentOptions,
            ],
            'call_number' => [
                'label' => '子類號',
                'required' => true,
                'type' => 'number',
            ],
            'comment' => [
                'label' => '範圍說明',
                'required' => false,
                'type' => 'textarea',
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
    public function store(StoreScopeRequest $request)
    {
        $this->authorize('create', Scope::class);

        $validatedData = $request->validated();

        // class_number 一律由父層推導,不接受呼叫端傳入——實測 16 筆 scope 的
        // class_number 全部等於其父層的 class_number,0 筆不一致,所以它是衍生值。
        $validatedData['class_number'] = Scope::findOrFail($validatedData['parent_class'])->class_number;

        $scope = Scope::firstOrCreate(
            ['name' => $validatedData['name']],
            $validatedData
        );

        return response()->json([
            'data' => new ScopeResource($scope->load('parent', 'children', 'siblings')),
            'message' => $scope->wasRecentlyCreated
                ? 'Scope created.'
                : 'Scope already exists.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope)
    {
        $scope->load([
            'subjectOf.object',
            'objectOf.subject',
            'parent.subjectOf',
            'parent.objectOf',
            'parent',
            'children',
            'siblings',
        ]);

        // 詳情頁那一排計數。跟上面的 load() 是兩件事：load() 撈的是要列出來的
        // 清單（子類、兄弟、述詞定義），loadCount() 補的是「屬於這個 scope 的
        // 實體有幾筆」——那組實體**不會**在這支端點列出來（可能上百筆，要分頁），
        // 只給數字。
        $scope->loadCount([
            'children',
            'siblings',
            'subjectOf',
            'objectOf',
            'documentations',
            'techniques',
            'implementations',
        ]);

        // withFormHints()：只有這支端點會把 new_child_call_number 算出來。
        // Triple 的 fetchCallNumberByClass() 打的就是這裡,拿它預填新增表單的子類號。
        // 清單頁跟巢狀資源不開,那是 N+1 的來源(見 ScopeResource 的註解)。
        return response()->json(
            (new ScopeResource($scope))->withFormHints()
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Scope $scope)
    {
        return $this->show($scope);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateScopeRequest $request, Scope $scope)
    {
        $this->authorize('update', $scope);

        $validatedData = $request->validated();

        // 跟 store 一樣:class_number 從 parent_class 推導,不讓呼叫端直接指定,
        // 否則可以把 class_number 改成跟父層對不上的值。
        $validatedData['class_number'] = Scope::findOrFail($validatedData['parent_class'])->class_number;

        $isUpdated = $scope->update($validatedData);

        return response()->json([
            'data' => $scope,
            'message' => $isUpdated
                ? 'Scope updated.'
                : 'Scope update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Scope $scope)
    {
        $this->authorize('delete', $scope);

        $scopeName = $scope->name;
        $scope->delete();

        return response()->json([
            'message' => "$scopeName was deleted.",
        ]);
    }
}
