<?php

namespace App\OpenApi;

use App\Http\ProblemDetails;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * 把 OpenAPI 文件裡的錯誤回應改成實際的格式：RFC 9457 problem details
 * （見 App\Http\ProblemDetails）。
 *
 * Scramble 從程式碼推得出「哪支端點會回哪些錯誤狀態碼」（驗證 422、未登入 401、
 * 無權限 403、查無資料 404、abort() 等），但它照 Laravel 預設格式描述內容
 * （只有 `message`／`errors`、`application/json`）。這裡統一把 4xx／5xx 的內容換成
 * `application/problem+json`，schema 指向共用的 `ProblemDetails`（422 是
 * `ValidationProblemDetails`），再替每支端點補上 Scramble 推不出來的 429。
 */
class ProblemDetailsResponses implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $components = $document->components;

        $problem = $components->addSchema('ProblemDetails', Schema::fromType($this->problemType()));
        $validationProblem = $components->addSchema('ValidationProblemDetails', Schema::fromType($this->validationProblemType()));

        foreach ($components->responses as $response) {
            $this->useProblemContent($response, $problem, $validationProblem);
        }

        $tooManyRequests = $components->add(
            new Reference('responses', 'TooManyRequests', $components),
            $this->tooManyRequestsResponse($problem),
        );

        /** @var Path $path */
        foreach ($document->paths as $path) {
            /** @var Operation $operation */
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Response) {
                        $this->useProblemContent($response, $problem, $validationProblem);
                    }
                }

                // routes/api.php 整組都套了 throttleApi（每分鐘 60 次／IP），每支端點都可能回 429。
                $operation->addResponse($tooManyRequests);
            }
        }
    }

    private function useProblemContent(Response $response, Reference $problem, Reference $validationProblem): void
    {
        $status = (int) $response->code;

        if ($status < 400) {
            return;
        }

        $response->content = [
            ProblemDetails::CONTENT_TYPE => $status === 422 ? $validationProblem : $problem,
        ];

        // Scramble 推不出說明時會填空字串或籠統的 "An error"，換成狀態碼的標準名稱。
        if (in_array($response->description, ['', 'An error'], true)) {
            $response->setDescription(HttpResponse::$statusTexts[$status] ?? '');
        }
    }

    private function problemType(): ObjectType
    {
        $type = new ObjectType;

        $type->addProperty('type', (new StringType)->format('uri-reference')->example('about:blank')
            ->setDescription('錯誤種類。目前一律是 `about:blank`，代表沒有比 HTTP 狀態碼更細的分類。'));
        $type->addProperty('title', (new StringType)->example('Not Found')
            ->setDescription('HTTP 狀態碼的標準名稱。'));
        $type->addProperty('status', (new IntegerType)->example(404)
            ->setDescription('HTTP 狀態碼，跟回應本身的狀態碼相同。'));
        $type->addProperty('detail', (new StringType)
            ->setDescription('這一次錯誤的說明，跟 `message` 相同。'));
        $type->addProperty('instance', (new StringType)->format('uri-reference')->example('/api/scopes/999')
            ->setDescription('出錯的請求路徑（不含查詢字串）。'));
        $type->addProperty('message', (new StringType)
            ->setDescription('Laravel 原本的錯誤訊息欄位，為了向下相容而保留，內容跟 `detail` 相同。'));

        $type->setRequired(['type', 'title', 'status', 'detail', 'instance', 'message']);

        return $type;
    }

    private function validationProblemType(): ObjectType
    {
        $type = $this->problemType();

        $type->addProperty('errors', (new ObjectType)
            ->additionalProperties((new ArrayType)->setItems(new StringType))
            ->setDescription('每個沒通過驗證的欄位與它的錯誤訊息（Laravel 原本的格式，保留）。'));
        $type->addProperty('locked_fields', (new ArrayType)->setItems(new StringType)
            ->setDescription('只有修改已被引用的述詞（relation）時才有：被鎖住、不能再修改的欄位。'));

        $type->addRequired(['errors']);

        return $type;
    }

    private function tooManyRequestsResponse(Reference $problem): Response
    {
        return Response::make(429)
            ->setDescription('超過頻率限制。等 `Retry-After` 秒之後再試。')
            ->setContent(ProblemDetails::CONTENT_TYPE, $problem)
            ->addHeader('Retry-After', new Header(
                description: '幾秒之後可以再送請求。',
                schema: Schema::fromType(new IntegerType),
            ))
            ->addHeader('X-RateLimit-Limit', new Header(
                description: '這段時間內允許的請求數。',
                schema: Schema::fromType(new IntegerType),
            ))
            ->addHeader('X-RateLimit-Remaining', new Header(
                description: '這段時間內還剩幾次。',
                schema: Schema::fromType(new IntegerType),
            ));
    }
}
