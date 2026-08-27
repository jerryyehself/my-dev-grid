<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImplementationRequest;
use App\Http\Requests\UpdateImplementationRequest;
use App\Http\Resources\ImplementationResource;
use App\Models\Implementation;
use App\Traits\SyncsPivotRelations;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ImplementationController extends Controller
{
    use SyncsPivotRelations;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $implementations = Implementation::with('scope')->orderBy('title')->get();

        return response()->json([
            "type" => Str::of(Implementation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            "data" => ImplementationResource::collection($implementations),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreImplementationRequest $request)
    {
        $data = $request->validated();
        $implementation = Implementation::create(Arr::except($data, ['documentations', 'techniques']));

        if (isset($data['documentations'])) {
            $implementation->documentations()->sync($this->pivotSyncData($data['documentations']));
        }
        if (isset($data['techniques'])) {
            $implementation->techniques()->sync($this->pivotSyncData($data['techniques']));
        }

        return response()->json([
            'data' => new ImplementationResource($implementation->load(['scope', 'documentations', 'techniques'])),
            'message' => 'Implementation created.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Implementation $implementation)
    {
        $implementation->load(['scope', 'documentations', 'techniques']);

        return response()->json(new ImplementationResource($implementation));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateImplementationRequest $request, Implementation $implementation)
    {
        $data = $request->validated();
        $isUpdated = $implementation->update(Arr::except($data, ['documentations', 'techniques']));

        if (isset($data['documentations'])) {
            $implementation->documentations()->sync($this->pivotSyncData($data['documentations']));
        }
        if (isset($data['techniques'])) {
            $implementation->techniques()->sync($this->pivotSyncData($data['techniques']));
        }

        return response()->json([
            'data' => new ImplementationResource($implementation->load(['scope', 'documentations', 'techniques'])),
            'message' => $isUpdated
                ? 'Implementation updated.'
                : 'Implementation update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Implementation $implementation)
    {
        $title = $implementation->title;
        $implementation->delete();

        return response()->json([
            'message' => "$title was deleted."
        ]);
    }
}
