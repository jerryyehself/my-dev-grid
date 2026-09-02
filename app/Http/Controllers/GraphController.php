<?php

namespace App\Http\Controllers;

use App\Models\Documentation;
use App\Models\DocumentationImplementationLink;
use App\Models\DocumentationTechniqueLink;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Technique;
use App\Models\TechniqueImplementationLink;
use Illuminate\Support\Collection;

class GraphController extends Controller
{
    /**
     * Build a nodes/edges graph across Documentation, Technique and
     * Implementation.
     *
     * Nodes are every row of the three domain entities. Edges come from
     * the three typed pivot tables (documentation<->technique,
     * documentation<->implementation, technique<->implementation) plus the
     * generic entity_relations table (same-type relations, e.g.
     * technique<->technique). Each edge's relation_id is resolved to the
     * Relation's predicate name so the frontend doesn't need a second
     * round trip.
     *
     * Shaped for a force-directed graph frontend: nodes as
     * {id, type, label}, edges as {source, target, predicate, label,
     * relation_id} where source/target reference node ids.
     */
    public function index()
    {
        $nodes = collect()
            ->concat($this->nodesFor(Documentation::all(), 'documentation'))
            ->concat($this->nodesFor(Technique::all(), 'technique'))
            ->concat($this->nodesFor(Implementation::all(), 'implementation'))
            ->values();

        $edges = collect()
            ->concat($this->pivotEdges(
                DocumentationTechniqueLink::with('relation')->get(),
                'documentation_id',
                'documentation',
                'technique_id',
                'technique',
            ))
            ->concat($this->pivotEdges(
                DocumentationImplementationLink::with('relation')->get(),
                'documentation_id',
                'documentation',
                'implementation_id',
                'implementation',
            ))
            ->concat($this->pivotEdges(
                TechniqueImplementationLink::with('relation')->get(),
                'technique_id',
                'technique',
                'implementation_id',
                'implementation',
            ))
            ->concat($this->entityRelationEdges())
            ->values();

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }

    private function nodeId(string $type, int $id): string
    {
        return "{$type}-{$id}";
    }

    private function nodesFor(Collection $models, string $type): Collection
    {
        return $models->map(fn ($model) => [
            'id' => $this->nodeId($type, $model->id),
            'type' => $type,
            'label' => $model->title,
        ]);
    }

    /**
     * Turn a collection of pivot link rows (each with a `relation_id`
     * foreign key and a `relation` relationship already loaded) into
     * graph edges. $sourceColumn/$targetColumn are the pivot's own FK
     * column names; $sourceType/$targetType are the node type they
     * resolve to.
     */
    private function pivotEdges(
        Collection $links,
        string $sourceColumn,
        string $sourceType,
        string $targetColumn,
        string $targetType,
    ): Collection {
        return $links->map(fn ($link) => $this->edge(
            $this->nodeId($sourceType, $link->$sourceColumn),
            $this->nodeId($targetType, $link->$targetColumn),
            $link->relation,
        ));
    }

    private function entityRelationEdges(): Collection
    {
        return EntityRelation::with('relation')->get()->map(fn (EntityRelation $entityRelation) => $this->edge(
            $this->nodeId($entityRelation->entity_type, $entityRelation->subject_id),
            $this->nodeId($entityRelation->entity_type, $entityRelation->object_id),
            $entityRelation->relation,
        ));
    }

    private function edge(string $source, string $target, $relation): array
    {
        return [
            'source' => $source,
            'target' => $target,
            'predicate' => optional($relation)->name,
            'label' => optional($relation)->name,
            'relation_id' => optional($relation)->id,
        ];
    }
}
