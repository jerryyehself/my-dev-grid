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
        $relations = Relation::with('parent')->orderBy('class_number')->orderBy('call_number')->with(['subject', 'object'])->get();

        return response()->json([
            "type" => Str::of(Relation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            "data" => RelationResource::collection($relations),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return response()->json(
            [
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
                    'options' => Scope::select('id', 'class_number', 'call_number', 'name')
                        ->orderBy('class_number')
                        ->distinct()->get(),
                    'class' => [
                        'w' => 'col-span-12 md:col-span-6 lg:col-span-4'
                    ]
                ],
                'object' => [
                    'label' => '客體',
                    'required' => true,
                    'type' => 'select',
                    'options' => Scope::select('id', 'class_number', 'call_number', 'name')
                        ->orderBy('class_number')
                        ->distinct()->get(),
                    'class' => [
                        'w' => 'col-span-12 md:col-span-6 lg:col-span-4'
                    ]
                ],
                'class_number' => [
                    'label' => '類號',
                    'required' => true,
                    'type' => 'label',
                    '' => '',
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
            ]
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRelationRequest $request)
    {
        $data = $request->validated();

        $relation = Relation::firstOrCreate(
            ['name' => $data['name']],
            $data
        );

        return response()->json([
            'data' => $relation,
            'message' => $relation->wasRecentlyCreated
                ? 'Scope created.'
                : 'Scope already exists.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Relation $relation)
    {
        return response()->json(
            new RelationResource($relation->load([
                'parent',
                'children'
            ]))
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
    public function update(UpdateRelationRequest $request, relation $relation)
    {
        $data = $request->validated();

        $updatedRelation = $relation->update($data);

        return response()->json([
            'data' => $relation,
            'message' =>  $updatedRelation
                ? 'Relation updated.'
                : 'Relation update faild',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Relation $relation)
    {
        $deletedRelation = $relation->delete();

        return response()->json(
            "{$relation->name} was deleted.",
            204
        );
    }
}
