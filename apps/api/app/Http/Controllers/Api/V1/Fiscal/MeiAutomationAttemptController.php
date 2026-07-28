<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ReadMeiAutomationArtifactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\DownloadMeiAutomationArtifactRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewMeiAutomationAttemptRequest;
use App\Http\Resources\Fiscal\MeiAutomationAttemptResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MeiAutomationAttemptController extends Controller
{
    public function __construct(
        private readonly ReadMeiAutomationArtifactAction $readArtifact,
    ) {}

    public function show(
        ViewMeiAutomationAttemptRequest $request,
        int $attempt,
    ): JsonResponse {
        return (new MeiAutomationAttemptResource($request->attempt()))->response();
    }

    public function download(
        DownloadMeiAutomationArtifactRequest $request,
        int $attempt,
        string $artifact,
    ): StreamedResponse {
        $download = $this->readArtifact->handle(
            $request->attempt(),
            $request->artifactId(),
        );

        return response()->streamDownload(static function () use ($download): void {
            echo $download->bytes;
        }, $download->name, [
            'Content-Type' => $download->contentType,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
