<?php

namespace App\Http\Controllers;

use App\Models\Technique;
use App\Services\SemanticRelations;
use App\Services\SemanticTechniqueEdges;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * 技術之間的上下位與相關：後台新增或刪除一條。
 *
 * 上下位只有 broader 一組端點：「把 A 加到 B 底下」與「把 B 加為 A 的下位」是同一條邊，都寫成 A broader B，
 * 反方向的 narrower 由讀的一方推出來。
 */
class TechniqueSemanticRelationController extends Controller
{
    #[Endpoint(
        title: '把技術掛到另一個技術底下（上下位）',
        description: '新增「這個技術的上位是 `broader`」一條邊；反方向（`broader` 的下位是這個技術）不用另外新增，一起成立。可以有多個上位。下列情況不新增，回 422：兩端是同一個技術；任一端已停用；任一端的分類不符合上下位述詞的規定；加上後上下位會繞成環、標了相關的兩個技術會有上下位路徑，或上位重複（已經能經別的上位走到）。已經有同一條時回 200、不重複新增；新增時回 201。回應是這個技術目前的上位、下位與相關（含由子述詞推出的，例如版本關係）。需要登入並有更新權限。',
    )]
    #[Response(200, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    #[Response(201, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    public function addBroader(Technique $technique, Technique $broader, SemanticTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $technique);
        $this->authorize('update', $broader);

        $created = $edges->add(SemanticRelations::BROADER, $technique, $broader);

        return $this->current($technique, $created ? 201 : 200);
    }

    #[Endpoint(
        title: '取消技術的上下位',
        description: '刪除「這個技術的上位是 `broader`」這條直接標的邊，反方向的下位一起消失。由子述詞推出的上下位（例如版本關係）不在這裡刪，沒有直接標的邊時回 404。已停用的技術也可以刪。回應是這個技術目前的上位、下位與相關。需要登入並有更新權限。',
    )]
    #[Response(200, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    #[Response(404, '這兩個技術之間沒有直接標的上下位。')]
    public function removeBroader(Technique $technique, Technique $broader, SemanticTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $technique);
        $this->authorize('update', $broader);

        $edges->remove(SemanticRelations::BROADER, $technique, $broader);

        return $this->current($technique, 200);
    }

    #[Endpoint(
        title: '標記兩個技術相關',
        description: '新增這個技術與 `related` 之間的相關。相關是對稱的，從哪一端新增都是同一條。下列情況不新增，回 422：兩端是同一個技術；任一端已停用；任一端的分類不符合相關述詞的規定；兩者之間有上下位路徑（隔幾層都算），或加上後會讓標了相關的兩個技術有上下位路徑。同一個上位底下的兩個技術沒有上下位路徑，可以標相關。已經有這一對時回 200、不重複新增；新增時回 201。回應是這個技術目前的上位、下位與相關。需要登入並有更新權限。',
    )]
    #[Response(200, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    #[Response(201, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    public function addRelated(Technique $technique, Technique $related, SemanticTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $technique);
        $this->authorize('update', $related);

        $created = $edges->add(SemanticRelations::RELATED, $technique, $related);

        return $this->current($technique, $created ? 201 : 200);
    }

    #[Endpoint(
        title: '取消兩個技術相關',
        description: '刪除這個技術與 `related` 之間直接標的相關，從哪一端刪都可以。由子述詞推出的相關不在這裡刪，沒有直接標的相關時回 404。已停用的技術也可以刪。回應是這個技術目前的上位、下位與相關。需要登入並有更新權限。',
    )]
    #[Response(200, type: 'array{data: array{id: int, broader: list<int>, narrower: list<int>, related: list<int>}, message: string}')]
    #[Response(404, '這兩個技術之間沒有直接標的相關。')]
    public function removeRelated(Technique $technique, Technique $related, SemanticTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $technique);
        $this->authorize('update', $related);

        $edges->remove(SemanticRelations::RELATED, $technique, $related);

        return $this->current($technique, 200);
    }

    private function current(Technique $technique, int $status): JsonResponse
    {
        return response()->json([
            'data' => ['id' => $technique->id] + SemanticRelations::fromDatabase()->around($technique->id),
            'message' => 'Technique updated.',
        ], $status);
    }
}
