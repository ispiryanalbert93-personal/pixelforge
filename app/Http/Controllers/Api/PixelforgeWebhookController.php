<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pixelforge\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookService $webhookService): JsonResponse
    {
        $result = $webhookService->handle(
            rawBody: $request->getContent(),
            parsedInput: $request->all(),
            eventIdHeader: $request->header('Pixelforge-Event-Id'),
            eventTypeHeader: $request->header('Pixelforge-Event-Type'),
        );

        return response()->json($result);
    }
}
