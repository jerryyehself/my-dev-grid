<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportArticleSourcesRequest;
use App\Models\Documentation;
use App\Services\ArticleSourceService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class ArticleSourceController extends Controller
{
    #[Endpoint(
        title: '列出可匯入的文章',
        description: '讀取設定好的文章來源 repo 最新版本，列出原稿 front matter 有 `import: true` 的 Markdown 檔，'
            .'以及它們跟站上文章的對應狀態：`new`（尚未匯入）、`changed`（來源有更新）、`unchanged`、'
            .'`conflict`（來源有更新，站上的內文也在匯入後改過）、`possible_match`（尚未連結，但有標題相同的文章）、'
            .'`site_deleted`（站上已刪除，不會再匯入）、`removed`（站上有連結，來源已經沒有這篇）、'
            .'`invalid`（不能匯入，原因在 `reason`）。`warnings` 是發布前要人工確認的內容（疑似憑證、email 等），只提醒不阻擋。'
            .'需要登入。來源未設定回 503（`reason: not_configured`）；GitHub 拒絕或出錯回 502，被 GitHub 限流回 503，'
            .'`reason` 說明是哪一種。',
    )]
    #[Response(200, type: 'array{repo: string, commit_sha: string, fetched_at: string, items: list<array{key: string|null, path: string, blob_sha: string|null, title: string|null, status: "new"|"changed"|"unchanged"|"removed"|"site_deleted"|"conflict"|"possible_match"|"invalid", documentation_id: int|null, published: bool|null, reason: string|null, reason_message: string|null, warnings: list<array{code: string, message: string, line: int|null}>}>}')]
    #[Response(502, 'GitHub 拒絕請求或回應錯誤，`reason` 說明是哪一種（例如 token 無效、沒有權限、找不到 repo）。')]
    #[Response(503, '文章來源未設定，或被 GitHub 限流（帶 `Retry-After`）。')]
    public function index(ArticleSourceService $articleSources): JsonResponse
    {
        $this->authorize('import', Documentation::class);

        return response()->json($articleSources->listing());
    }

    #[Endpoint(
        title: '匯入文章',
        description: '依清單的選擇匯入：`create` 新增成草稿；`update` 用來源的內容更新已連結的文章，不改它的發布狀態；'
            .'`link` 把來源記到既有文章上，不改標題與內文。伺服器會以 `commit_sha` 那個版本重新讀取，'
            .'檔案跟 `expected_blob_sha` 不同、或站上的狀態已經改變時回 409，整批都不寫入，請重新列清單。'
            .'狀態是 `conflict` 的項目要帶 `overwrite_site_edits: true` 才會更新。需要登入。',
    )]
    #[Response(200, type: 'array{commit_sha: string, data: list<array{key: string, action: "create"|"update"|"link", documentation_id: int, title: string, published: bool, warnings: list<array{code: string, message: string, line: int|null}>}>, message: string}')]
    #[Response(409, '清單已過期（`reason: stale`）或站上的狀態已經改變（`reason: conflict`）。`errors` 以請求欄位為 key 列出原因。')]
    #[Response(502, 'GitHub 拒絕請求或回應錯誤，`reason` 說明是哪一種。')]
    #[Response(503, '文章來源未設定、找不到文章分類，或被 GitHub 限流（帶 `Retry-After`）。')]
    public function import(ImportArticleSourcesRequest $request, ArticleSourceService $articleSources): JsonResponse
    {
        $this->authorize('import', Documentation::class);

        $data = $request->validated();
        $items = array_map(fn (array $item) => [
            'key' => $item['key'],
            'expected_blob_sha' => $item['expected_blob_sha'],
            'action' => $item['action'],
            'documentation_id' => isset($item['documentation_id']) ? (int) $item['documentation_id'] : null,
            'overwrite_site_edits' => filter_var($item['overwrite_site_edits'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], array_values($data['items']));

        return response()->json([
            'commit_sha' => $data['commit_sha'],
            'data' => $articleSources->import($data['commit_sha'], $items),
            'message' => 'Article sources imported.',
        ]);
    }
}
