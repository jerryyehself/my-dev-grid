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
            "type" => Str::of(Scope::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            "data" => ScopeResource::collection($scopeList),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $classNumberOptions = Scope::select('id', 'class_number', 'name')
            ->where('parent_class', null)
            ->orderBy('class_number')
            ->distinct()->get();

        return response()->json([
            'id' => [
                'required' => false,
                'type' => 'hidden'
            ],
            'name' => [
                'label' => '名稱',
                'required' => true,
                'type' => 'text'
            ],
            'class_number' => [
                'label' => '類號',
                'required' => true,
                'type' => 'select',
                'options' => $classNumberOptions,
            ],
            'call_number' => [
                'label' => '子類號',
                'required' => true,
                'type' => 'number',
            ],
            'comment' => [
                'label' => '範圍說明',
                'required' => false,
                'type' => 'textarea'
            ],
            'note' => [
                'label' => '註釋',
                'required' => false,
                'type' => 'textarea'
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScopeRequest $request)
    {
        $validatedData = $request->validated();

        $validatedData['parent_class'] = $validatedData['class_number'];
        $validatedData['class_number'] = Scope::find($validatedData['parent_class'])->class_number;

        $scope = Scope::firstOrCreate(
            ['name' => $validatedData['name']],
            $validatedData
        );

        return response()->json([
            'data' => new ScopeResource($scope->load('parent', 'children')),
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
            'children'
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
        $validatedData = $request->validated();

        $isUpdated = $scope->update($validatedData);

        return response()->json([
            'data' => $scope,
            'message' =>  $isUpdated
                ? 'Scope updated.'
                : 'Scope update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Scope $scope)
    {
        $scopeName = $scope->name;
        $scope->delete();
        return response()->json([
            'message' => "$scopeName was deleted."
        ]);
    }
}
