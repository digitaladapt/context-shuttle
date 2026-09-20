<?php

declare(strict_types=1);

namespace App\Health;

use App\ToolRegistry\ToolRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Health/readiness endpoints (Guiding Light §8.4).
 *
 * /health is liveness only — no dependencies, safe for the most aggressive
 * prober. /ready verifies the tool registry actually loaded and reports the
 * tool count; it may be extended to check upstream dependencies.
 */
final class HealthController
{
    public function __construct(
        private ToolRegistry $registry,
    ) {}

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }

    public function ready(): JsonResponse
    {
        try {
            $count = $this->registry->count();
        } catch (Throwable $e) {
            return new JsonResponse([
                'status' => 'unavailable',
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'status' => 'ready',
            'tools' => $count,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
