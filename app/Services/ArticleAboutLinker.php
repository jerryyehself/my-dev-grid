<?php

namespace App\Services;

use App\Logging\AdminAudit;
use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 匯入文章時，依 front matter 的 `about_techniques`、`about_projects`（見 `ArticleSourceParser`）
 * 把文章連到站上的技術與專案。連結存在 `documentation_technique`、`documentation_implementation`，
 * 跟後台編輯器寫的是同一批連結表，述詞是 `about`（技術）與 `aboutProject`（專案），見 `RelationSeeder`。
 *
 * 只新增，不刪除也不改：
 * - 名稱不在清單上的連結一律不動，不管是後台加的還是上次匯入加的，所以拿掉 front matter 的某一項
 *   不會讓連結消失，要取消請在後台刪。
 * - 文章已經連到那個技術（或專案）就跳過，不論用的是哪個述詞。後台編輯器一次存一整份清單，
 *   而且一個技術只對應一個述詞（`sync()` 以技術 id 為鍵）；同一個技術連兩次，下次在後台存檔就會
 *   撞到連結表的唯一約束。這也讓重複匯入不會產生重複的連結。
 * - 名稱對不上、對上不只一筆、分類不符述詞，都只回警告，不自動建立技術或專案，也不猜。
 *
 * 名稱比對不分大小寫：技術比對顯示名稱（`Technique::labelFrom()`，有版本是「Vue 3」，沒有是「Vue」），
 * 專案比對 `title`。技術與專案都沒有 slug 欄位，所以只認名稱。
 *
 * 連結前檢查兩端的分類符合述詞的主詞與受詞（跟 `RelationFitsLinkedScopes` 同一條規則）：
 * 後台編輯器存檔時會逐筆重新驗證現有連結，這裡寫進不符的連結，會讓那篇文章在後台存不了檔。
 */
final class ArticleAboutLinker
{
    /** 種類 => [述詞名稱, 在警告裡怎麼稱呼, front matter 欄位]。 */
    private const KINDS = [
        'technique' => ['about', '技術', 'about_techniques'],
        'project' => ['aboutProject', '專案', 'about_projects'],
    ];

    /** @param  NomenRecorder  $nomens  影子模式：另外記下 front matter 的名稱，不影響這裡建的連結 */
    public function __construct(private readonly NomenRecorder $nomens) {}

    /**
     * @param  array{techniques: list<string>, projects: list<string>}  $about
     * @return list<array{code: string, message: string, line: int|null}> 警告；沒有問題就是空的
     */
    public function link(Documentation $documentation, array $about): array
    {
        $warnings = [];
        $ancestry = null;

        foreach (['technique' => $about['techniques'], 'project' => $about['projects']] as $kind => $names) {
            if ($names === []) {
                continue;
            }
            [$predicate, $noun, $field] = self::KINDS[$kind];

            $relation = Relation::where('name', $predicate)->first();
            if ($relation === null) {
                $warnings[] = self::warning('about_predicate_missing', "找不到述詞「{$predicate}」，{$field} 沒有建立任何連結。");

                continue;
            }

            $ancestry ??= ScopeAncestry::fromDatabase();
            $link = $kind === 'technique' ? $documentation->techniques() : $documentation->implementations();
            $candidates = $this->candidates($kind);
            $linked = array_flip($link->pluck($link->getRelated()->getQualifiedKeyName())->all());

            $attach = [];
            foreach ($names as $name) {
                $matches = $candidates[mb_strtolower($name)] ?? [];

                if ($matches === []) {
                    $warnings[] = self::warning('about_not_found', "{$field} 的「{$name}」找不到對應的{$noun}，沒有建立連結。");
                } elseif (count($matches) > 1) {
                    $warnings[] = self::warning('about_ambiguous', "{$field} 的「{$name}」對上不只一筆{$noun}，沒有建立連結。");
                } elseif (! $ancestry->matches($this->type($documentation), (int) $relation->subject_id)
                    || ! $ancestry->matches($this->type($matches[0]), (int) $relation->object_id)) {
                    $warnings[] = self::warning('about_scope_mismatch', "{$field} 的「{$name}」所屬的分類不符合述詞「{$predicate}」，沒有建立連結。");
                } elseif (! isset($linked[$matches[0]->getKey()])) {
                    $attach[$matches[0]->getKey()] = ['relation_id' => $relation->id];
                }
            }

            $this->attach($link, $attach);
        }

        $this->nomens->recordArticle($documentation, $about);

        return $warnings;
    }

    /**
     * 站上所有的技術或專案，以小寫的名稱為鍵。同名的收在同一個鍵底下，讓呼叫端分辨「對上一筆」與「對上不只一筆」。
     *
     * 在 PHP 裡轉小寫，不靠資料庫的 `LOWER()`：SQLite 的 `LOWER()` 只轉 ASCII，PostgreSQL 依資料庫的語系，
     * 兩邊對中文以外的字母（例如帶重音的）結果會不同。技術與專案的筆數是幾百筆的量級，整份撈出來沒有問題。
     *
     * @return array<string, list<Model>>
     */
    private function candidates(string $kind): array
    {
        $models = $kind === 'technique' ? Technique::all(['id', 'type', 'title', 'version']) : Implementation::all(['id', 'type', 'title']);

        $byName = [];
        foreach ($models as $model) {
            $name = $model instanceof Technique ? $model->label : $model->title;
            $byName[mb_strtolower(trim($name))][] = $model;
        }

        return $byName;
    }

    private function type(Model $model): ?int
    {
        return $model->type === null ? null : (int) $model->type;
    }

    /**
     * @param  array<int, array{relation_id: int}>  $attach  實體 id => 連結表欄位
     */
    private function attach(BelongsToMany $link, array $attach): void
    {
        if ($attach === []) {
            return;
        }

        $link->attach($attach);
        // pivot 沒有自己的 model，attach() 不會觸發 model 事件，跟後台編輯器一樣交給稽核紀錄補記
        AdminAudit::pivotSynced($link, ['attached' => array_keys($attach)]);
    }

    /** @return array{code: string, message: string, line: null} */
    private static function warning(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'line' => null];
    }
}
