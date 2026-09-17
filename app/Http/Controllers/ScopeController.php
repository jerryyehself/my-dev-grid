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
                'label' => '上層分類',
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

        return response()->json(
            new ScopeResource($scope)
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
