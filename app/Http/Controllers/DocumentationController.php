<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentationRequest;
use App\Http\Requests\UpdateDocumentationRequest;
use App\Http\Resources\DocumentationResource;
use App\Models\Documentation;
use App\Traits\SyncsPivotRelations;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DocumentationController extends Controller
{
    use SyncsPivotRelations;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $documentations = Documentation::with('scope')->orderBy('title')->get();

        return response()->json([
            'type' => Str::of(Documentation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => DocumentationResource::collection($documentations),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDocumentationRequest $request)
    {
        $data = $request->validated();
        $documentation = Documentation::create(Arr::except($data, ['techniques', 'implementations']));

        if (isset($data['techniques'])) {
            $documentation->techniques()->sync($this->pivotSyncData($data['techniques']));
        }
        if (isset($data['implementations'])) {
            $documentation->implementations()->sync($this->pivotSyncData($data['implementations']));
        }

        return response()->json([
            'data' => new DocumentationResource($documentation->load(['scope', 'techniques', 'implementations'])),
            'message' => 'Documentation created.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Documentation $documentation)
    {
        $documentation->load(['scope', 'techniques', 'implementations']);

        return response()->json(new DocumentationResource($documentation));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDocumentationRequest $request, Documentation $documentation)
    {
        $data = $request->validated();
        $isUpdated = $documentation->update(Arr::except($data, ['techniques', 'implementations']));

        if (isset($data['techniques'])) {
            $documentation->techniques()->sync($this->pivotSyncData($data['techniques']));
        }
        if (isset($data['implementations'])) {
            $documentation->implementations()->sync($this->pivotSyncData($data['implementations']));
        }

        return response()->json([
            'data' => new DocumentationResource($documentation->load(['scope', 'techniques', 'implementations'])),
            'message' => $isUpdated
                ? 'Documentation updated.'
                : 'Documentation update failed',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Documentation $documentation)
    {
        $title = $documentation->title;
        $documentation->delete();

        return response()->json([
            'message' => "$title was deleted.",
        ]);
    }
}
