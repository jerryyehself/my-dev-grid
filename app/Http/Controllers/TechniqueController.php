<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTechniqueRequest;
use App\Http\Requests\UpdateTechniqueRequest;
use App\Http\Resources\TechniqueResource;
use App\Models\Technique;
use App\Traits\SyncsPivotRelations;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TechniqueController extends Controller
{
    use SyncsPivotRelations;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $techniques = Technique::with('scope')->orderBy('title')->get();

        return response()->json([
            'type' => Str::of(Technique::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => TechniqueResource::collection($techniques),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTechniqueRequest $request)
    {
        $data = $request->validated();
        $technique = Technique::create(Arr::except($data, ['documentations', 'implementations']));

        if (isset($data['documentations'])) {
            $technique->documentations()->sync($this->pivotSyncData($data['documentations']));
        }
        if (isset($data['implementations'])) {
            $technique->implementations()->sync($this->pivotSyncData($data['implementations']));
        }

        return response()->json([
            'data' => new TechniqueResource($technique->load(['scope', 'documentations', 'implementations'])),
            'message' => 'Technique created.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Technique $technique)
    {
        $technique->load(['scope', 'documentations', 'implementations']);

        return response()->json(new TechniqueResource($technique));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTechniqueRequest $request, Technique $technique)
    {
        $data = $request->validated();
        $isUpdated = $technique->update(Arr::except($data, ['documentations', 'implementations']));

        if (isset($data['documentations'])) {
            $technique->documentations()->sync($this->pivotSyncData($data['documentations']));
        }
        if (isset($data['implementations'])) {
            $technique->implementations()->sync($this->pivotSyncData($data['implementations']));
        }

        return response()->json([
            'data' => new TechniqueResource($technique->load(['scope', 'documentations', 'implementations'])),
            'message' => $isUpdated
                ? 'Technique updated.'
                : 'Technique update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Technique $technique)
    {
        $title = $technique->title;
        $technique->delete();

        return response()->json([
            'message' => "$title was deleted.",
        ]);
    }
}
