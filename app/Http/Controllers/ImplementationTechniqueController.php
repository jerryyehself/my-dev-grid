<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateImplementationTechniqueRequest;
use App\Http\Resources\ImplementationResource;
use App\Models\Implementation;
use App\Models\Technique;
use App\Services\ManualTechniqueEdges;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * 一對專案與技術之間那條邊的述詞：站主指定，或交還給 GitHub 同步。
 */
class ImplementationTechniqueController extends Controller
{
    #[Endpoint(
        title: '指定專案與技術之間的關係',
        description: '把這個專案與這個技術之間的邊改成指定的關係，並標成手動指定：之後 GitHub 同步不再改這一對的關係，也不另外新增邊。這一對沒有邊時回 404；同一對已經有另一條邊用這個關係時回 422；同一對有多條邊、分不出要改哪一條時回 409。需要登入並有更新權限。',
    )]
    #[Response(404, '這個專案與技術之間沒有邊。')]
    #[Response(409, '同一對有多條邊、分不出要改哪一條。先刪掉多出來的那一列，或聯絡維護者。')]
    public function update(UpdateImplementationTechniqueRequest $request, Implementation $implementation, Technique $technique, ManualTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $implementation);

        $edges->set($implementation, $technique, (int) $request->validated('relation_id'));

        return $this->updated($implementation);
    }

    #[Endpoint(
        title: '把專案與技術之間的關係交還給同步',
        description: '清掉這一對的手動指定標記，之後由 GitHub 同步依程式裡的證據決定關係。這一對沒有邊時回 404；本來就沒有標記時照樣回 200。需要登入並有更新權限。',
    )]
    #[Response(404, '這個專案與技術之間沒有邊。')]
    public function releaseToSync(Implementation $implementation, Technique $technique, ManualTechniqueEdges $edges): JsonResponse
    {
        $this->authorize('update', $implementation);

        $edges->release($implementation, $technique);

        return $this->updated($implementation);
    }

    private function updated(Implementation $implementation): JsonResponse
    {
        return response()->json([
            'data' => new ImplementationResource($implementation->load(['scope', 'techniques'])),
            'message' => 'Implementation updated.',
        ]);
    }
}
