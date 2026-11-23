<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentationRequest;
use App\Http\Requests\UpdateDocumentationRequest;
use App\Http\Resources\DocumentationResource;
use App\Models\Documentation;
use App\Services\ArticleUnitSync;
use App\Traits\SyncsPivotRelations;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DocumentationController extends Controller
{
    use SyncsPivotRelations;

    public function __construct(private readonly ArticleUnitSync $unitSync) {}

    #[Endpoint(
        title: '列出文件',
        description: '回傳所有文件，依標題排序，每筆含所屬分類與關聯的技術、實作。未登入時只看得到已發布的文件，草稿要登入才會出現；關聯的實作也不含設為不公開的。',
    )]
    public function index()
    {
        $documentations = Documentation::visibleToViewer()
            ->with(['scope', 'techniques', 'implementations' => fn ($query) => $query->visibleToViewer()])
            ->orderBy('title')
            ->get();

        return response()->json([
            'type' => Str::of(Documentation::class)
                ->classBasename()
                ->lower()
                ->plural()
                ->toString(),
            'data' => DocumentationResource::collection($documentations),
        ]);
    }

    #[Endpoint(
        title: '建立文件',
        description: '建立一篇文件，並可同時指定關聯的技術與實作。需要登入並有建立權限。分類是文章（post）時，內文會一併切成段落單位；標題行尾的 {#id} 有重複或太長時回 422，文件不會建立。',
    )]
    public function store(StoreDocumentationRequest $request)
    {
        $this->authorize('create', Documentation::class);

        $data = $request->validated();

        $documentation = DB::transaction(function () use ($data) {
            $documentation = Documentation::create(Arr::except($data, ['techniques', 'implementations']));

            if (isset($data['techniques'])) {
                $this->syncPivot($documentation->techniques(), $data['techniques']);
            }
            if (isset($data['implementations'])) {
                $this->syncPivot($documentation->implementations(), $data['implementations']);
            }
            $this->syncUnits($documentation);

            return $documentation;
        });

        return response()->json([
            'data' => new DocumentationResource($documentation->load(['scope', 'techniques', 'implementations'])),
            'message' => 'Documentation created.',
        ], 201);
    }

    #[Endpoint(
        title: '取得文件',
        description: '回傳單一文件，含所屬分類與關聯的技術、實作（未登入時不含設為不公開的實作）。草稿對未登入的人等同不存在，回 404。',
    )]
    public function show(Documentation $documentation)
    {
        // 草稿對沒登入的人等於不存在：回 404 而不是 403，不透露「這個 id 有一篇草稿」
        abort_unless($documentation->isPublished() || Documentation::viewerCanSeeDrafts(), 404);

        $documentation->load(['scope', 'techniques', 'implementations' => fn ($query) => $query->visibleToViewer()]);

        return response()->json(new DocumentationResource($documentation));
    }

    #[Endpoint(
        title: '更新文件',
        description: '更新文件欄位；有帶技術或實作時一併同步關聯。需要登入並有更新權限。分類是文章（post）而且內文有改時，段落單位會一併更新；標題行尾的 {#id} 有重複或太長時回 422，文件不會更新。',
    )]
    public function update(UpdateDocumentationRequest $request, Documentation $documentation)
    {
        $this->authorize('update', $documentation);

        $data = $request->validated();

        $isUpdated = DB::transaction(function () use ($data, $documentation) {
            $isUpdated = $documentation->update(Arr::except($data, ['techniques', 'implementations']));

            if (isset($data['techniques'])) {
                $this->syncPivot($documentation->techniques(), $data['techniques']);
            }
            if (isset($data['implementations'])) {
                $this->syncPivot($documentation->implementations(), $data['implementations']);
            }
            $this->syncUnits($documentation);

            return $isUpdated;
        });

        return response()->json([
            'data' => new DocumentationResource($documentation->load(['scope', 'techniques', 'implementations'])),
            'message' => $isUpdated
                ? 'Documentation updated.'
                : 'Documentation update failed',
        ]);
    }

    #[Endpoint(
        title: '刪除文件',
        description: '刪除指定的文件。需要登入並有刪除權限。',
    )]
    public function destroy(Documentation $documentation)
    {
        $this->authorize('delete', $documentation);

        $title = $documentation->title;
        $documentation->delete();

        return response()->json([
            'message' => "$title was deleted.",
        ]);
    }

    /**
     * 文章的內文有變就重新切段落單位，跟寫文件在同一個 transaction（呼叫端包著），跟匯入同一個做法。
     *
     * 新增，或更新時內文或分類變了才同步：只改標題等欄位時單位不動；分類改成文章時，
     * 內文沒變也要切一次。不是文章的文件不切段。
     *
     * @throws ValidationException 標題行尾的 `{#id}` 重複或太長（422，文件的變更連同其他寫入一起回滾）
     */
    private function syncUnits(Documentation $documentation): void
    {
        if (! ($documentation->wasRecentlyCreated || $documentation->wasChanged(['body', 'type'])) || ! $documentation->isArticle()) {
            return;
        }

        try {
            $this->unitSync->sync($documentation);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body' => [$e->getMessage()]]);
        }
    }
}
