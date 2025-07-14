<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRelationRequest;
use App\Http\Requests\UpdateRelationRequest;
use App\Http\Resources\RelationResource;
use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Support\Str;

class RelationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $relationList = Relation::with(['parent', 'subject', 'object'])
            ->orderBy('class_number')
            ->orderBy('call_number')
            ->get();

        return response()->json([
            "type" => Str::of(Relation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            "data" => RelationResource::collection($relationList),
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
                'type' => 'hidden'
            ],
            'name' => [
                'label' => '名稱',
                'required' => true,
                'type' => 'text',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4'
                ]
            ],
            'subject' => [
                'label' => '主體',
                'required' => true,
                'type' => 'select',
                'options' => $scopeOptions,
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4'
                ]
            ],
            'object' => [
                'label' => '客體',
                'required' => true,
                'type' => 'select',
                'options' => $scopeOptions,
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-4'
                ]
            ],
            'class_number' => [
                'label' => '類號',
                'required' => true,
                'type' => 'label',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-6'
                ]
            ],
            'call_number' => [
                'label' => '子類號',
                'required' => true,
                'type' => 'number',
                'class' => [
                    'w' => 'col-span-12 md:col-span-6 lg:col-span-6'
                ]
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
    public function store(StoreRelationRequest $request)
    {
        $validatedData = $request->validated();

        if ($validatedData['call_number'] != '00') {
            $validatedData['parent_number'] = Relation::where('class_number', $validatedData['class_number'])
                ->where('call_number', '00')
                ->value('id');
        }

        $relation = Relation::firstOrCreate(
            ['name' => $validatedData['name']],
            $validatedData
        );

        return response()->json([
            'data' => new RelationResource($relation),
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
        return response()->json(
            new RelationResource($relation)
        );
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
        $validatedData = $request->validated();

        $isUpdated = $relation->update($validatedData);

        return response()->json([
            'data' => new RelationResource($relation),
            'message' =>  $isUpdated
                ? 'Relation updated.'
                : 'Relation update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Relation $relation)
    {
        $relationName = $relation->name;
        $relation->delete();

        return response()->json([
            'message' => "$relationName was deleted."
        ]);
    }
}
