<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeWebhookSignature
{
    private const int TOLERANCE_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.pixelforge.webhook_secret');
        $signatureHeader = $request->header('Pixelforge-Signature');

        if (! is_string($secret) || $secret === '' || ! is_string($signatureHeader) || $signatureHeader === '') {
            return $this->unauthorizedResponse();
        }

        [$timestamp, $v1Signatures] = $this->parseSignatureHeader($signatureHeader);

        if ($timestamp === null || $v1Signatures === [] || ! ctype_digit($timestamp)) {
            return $this->unauthorizedResponse();
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return $this->unauthorizedResponse();
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        foreach ($v1Signatures as $candidate) {
            if (hash_equals($expectedSignature, $candidate)) {
                return $next($request);
            }
        }

        return $this->unauthorizedResponse();
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $v1Signatures = [];

        foreach (explode(',', $header) as $segment) {
            $parts = explode('=', trim($segment), 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $v1Signatures[] = $value;
            }
        }

        return [$timestamp, $v1Signatures];
    }

    private function unauthorizedResponse(): Response
    {
        return response()->json(['error' => 'Invalid webhook signature.'], 401);
    }
}
