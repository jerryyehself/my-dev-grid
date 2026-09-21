<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 一次性回填：把 `RelationSeeder.php` 既有 15 筆 relation 的 `note` 裡已經寫明
 * 的出處，結構化進上一支 migration 新增的 `source_vocabulary`/`source_term`。
 *
 * **判斷規則只有一條，逐筆列在下面的理由裡：只用 `note` 裡已經寫出來的詞彙表跟
 * 詞條名，不額外去查、也不自己補一個沒被寫過的詞條名。** 這是刻意的範圍收斂——
 * 這支 migration 的工作是「把已知事實搬進可查詢的欄位」，不是「重新做一次
 * 外部詞彙的研究」，那件事本來就已經在 `RelationSeeder.php` 的 note 裡做過了。
 * 這條規則在少數幾筆製造出真正的取捨，下面逐筆註明：
 *
 * - `specs`／`specifiedBy`：note 明寫「查無乾淨的外部對應，維持為本專案自創」，
 *   兩者都是 `null`。這是分類帳裡「對照組排除範圍」的第一條邊界。
 * - `uses`／`used`：note 只對 `uses` 這一筆寫，同時具名 SPDX `DEPENDS_ON` 與
 *   `DEPENDENCY_OF` 這一對（外加提及 PROV-O `prov:used`，但 note 自己說「不是
 *   字面上的 PROV-O 對應」，所以不採用 PROV-O 當主要出處）。`DEPENDS_ON`／
 *   `DEPENDENCY_OF` 本來就是 SPDX 裡對應的一組正反述詞，方向也對得上
 *   （`uses` 是「技術依賴於實作」＝ depends on，`used` 是它的反向），所以兩個
 *   詞條分別指派給對應方向，不是憑空發明。
 * - `documents`／`documentedBy`：note 只對 `documents` 具名 SPDX
 *   `DOCUMENTATION_OF`；`documentedBy` 的 note 只寫「Reverse of documents」，
 *   沒有另外具名一個反向詞條，而且**本專案沒有查證過 SPDX 是否真的有一個
 *   正式命名的反向詞條**（不像下面 `descendantOf`／`ancestorOf` 兩個都各自
 *   具名）。與其編一個沒查證過的詞條名（那正是這個欄位要避免的事——記錄
 *   事實不是記錄推測），`documentedBy` 沿用跟 `documents` 相同的
 *   `(spdx, DOCUMENTATION_OF)`：這是同一個外部關係概念、只是從另一個方向看，
 *   不是另一個詞條。
 * - `requires`／`isRequiredBy`：兩者的 note 都各自具名（`dcterms:requires`／
 *   `dcterms:isRequiredBy`），直接照抄。
 * - `assists`／`assisted-by`：note 是同一段文字套用在兩筆上，具名的是
 *   「SPDX 3.0.1 usesTool／dependsOn 的**平輩先例**」——這句話borrow 的是
 *   「兩者該是平輩不是父子」這個**結構**，不是逐字宣稱 assists 就是
 *   usesTool。依語意最接近的方向指派（assists＝借助工具但非直接產出，
 *   對應 usesTool；assisted-by 對應 dependsOn），跟 `uses`／`used` 那組
 *   同樣的處理方式，但誠實標記：這裡的把握比上面幾組低，是「同一份精確
 *   具名的兩個詞條，依語意就近指派方向」，不是「note 逐字寫明了方向」。
 * - `descendantOf`／`ancestorOf`：兩者的 note 都各自具名 SPDX `DESCENDANT_OF`／
 *   `ANCESTOR_OF`，直接照抄。
 * - `precedes`／`accompanies`：note 各自具名 Tillett (1987) 的類別名稱
 *   （Sequential／Accompanying）。`succeeds` 的 note 只寫「Reverse of
 *   precedes」，沒有另外具名，處理方式同 `documentedBy`：沿用
 *   `precedes` 的 `(tillett1987, Sequential)`。
 */
return new class extends Migration
{
    /** @var array<string, array{string|null, string|null}> */
    private const SOURCES = [
        'specs' => [null, null],
        'specifiedBy' => [null, null],

        'documents' => ['spdx', 'DOCUMENTATION_OF'],
        'documentedBy' => ['spdx', 'DOCUMENTATION_OF'],

        'uses' => ['spdx', 'DEPENDS_ON'],
        'used' => ['spdx', 'DEPENDENCY_OF'],

        'requires' => ['dcterms', 'requires'],
        'isRequiredBy' => ['dcterms', 'isRequiredBy'],

        'assists' => ['spdx', 'usesTool'],
        'assisted-by' => ['spdx', 'dependsOn'],

        'descendantOf' => ['spdx', 'DESCENDANT_OF'],
        'ancestorOf' => ['spdx', 'ANCESTOR_OF'],

        'accompanies' => ['tillett1987', 'Accompanying'],
        'precedes' => ['tillett1987', 'Sequential'],
        'succeeds' => ['tillett1987', 'Sequential'],
    ];

    public function up(): void
    {
        foreach (self::SOURCES as $name => [$vocabulary, $term]) {
            DB::table('relations')
                ->where('name', $name)
                ->update([
                    'source_vocabulary' => $vocabulary,
                    'source_term' => $term,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('relations')->update([
            'source_vocabulary' => null,
            'source_term' => null,
        ]);
    }
};
