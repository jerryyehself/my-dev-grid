<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScopeRequest;
use App\Http\Requests\UpdateScopeRequest;
use App\Models\Scope;

class ScopeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $scopes = Scope::with('parent')->orderBy('class_number')->orderBy('call_number')->get();

        return response()->json(
            ["type" => strtolower(class_basename(Scope::class)), "data" => $scopes]
        );
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return response()->json(
            ['form' => [
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
                    'options' => Scope::select('id', 'class_number', 'name')
                        ->where('parent_class', null)
                        ->orderBy('class_number')
                        ->distinct()->get()
                ],
                'call_number' => [
                    'label' => '子類號',
                    'required' => true,
                    'type' => 'number'
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
            ]]
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScopeRequest $request)
    {
        $data = $request->validated();

        $scope = Scope::firstOrCreate(
            ['name' => $data['name']],
            $data
        );

        return response()->json([
            'data' => $scope,
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
        $scopeData = $scope->load(['subjectOf.object', 'objectOf.subject']);

        $mainScopeData = Scope::where('class_number', $scope->class_number)->get();

        return response()->json(
            [
                'scope' => $scopeData,
                'mainScope' => $mainScopeData
            ]
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
    public function update(UpdateScopeRequest $request, scope $scope)
    {

        $data = $request->validated();

        $updatedScope = $scope->update($data);

        return response()->json([
            'data' => $scope,
            'message' =>  $updatedScope
                ? 'Scope updated.'
                : 'Scope update faild',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Scope $scope)
    {
        $deletedScope = $scope->delete();
        return response()->json(
            "{$scope->name} wad deleted.",
            204
        );
    }
}
