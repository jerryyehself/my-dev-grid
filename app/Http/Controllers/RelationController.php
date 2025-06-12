<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRelationRequest;
use App\Http\Requests\UpdateRelationRequest;
use App\Models\Relation;

class RelationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $relations = Relation::with(['subject', 'object'])->get();

        return response()->json(['data' => $relations]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // return Relation::create([]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRelationRequest $request)
    {
        $data = $request->validated();

        $relation = Relation::firstOrCreate(
            ['title' => $data['title']],
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
        $relationData = $relation->load(['subject', 'object']);

        $mainRelationData = Relation::with(['subject', 'object'])->where('class_number', $relation->class_number)->get();

        return response()->json(
            [
                'relation' => $relationData,
                'mainRelation' => $mainRelationData
            ]
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
            "{$relation->title} was deleted.",
            204
        );
    }
}
