<?php

declare(strict_types=1);

namespace App\Alerts;

use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /inputs/{id} — the harness-facing status surface.
 *
 * Deliberately outside the tool pipeline (docs/design/ALERTS.md): a
 * harness polling every few seconds must not flood the mcp_invocation
 * log — a 5-second cadence over a 10-minute TTL is 120 log lines per
 * request. Tool-grade interactions go through `get_user_answer`
 * (Phase 2); machine-grade polling goes here.
 *
 * Short polling only in v1 — no `?wait=` long-poll hold. The endpoint is
 * stateless and trivially cacheable; a long-poll would pin a worker per
 * waiting client, exactly the cost the design avoids. Revisit only if
 * real poll volume proves it necessary.
 *
 * Responses:
 *   200 — {status: "pending"|"answered"|"expired", question, type,
 *          expires_at, answered_at?, answer?}
 *   404 — unknown or malformed id: {status: "not_found"}
 *
 * `pending` and `expired` are normal answers, not errors. `answer` is
 * only present once answered — value is a string (text) or bool
 * (confirm). The response never lists other requests and never echoes
 * secrets; the id is the capability, so knowing it is sufficient.
 */
final class InputStatusController extends AbstractController
{
    public function __construct(
        private PendingRequestStore $store,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/inputs/{id}', name: 'alerts_input_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $request = $this->store->find($id, $this->clock->now());

        if (null === $request) {
            return new JsonResponse(['status' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = [
            'status' => $request->status->value,
            'type' => $request->type->value,
            'question' => $request->question,
            'expires_at' => $request->expiresAt->format(DateTimeInterface::ATOM),
        ];

        if (RequestStatus::Answered === $request->status) {
            $payload['answer'] = $request->answer;
            $payload['answered_at'] = $request->answeredAt?->format(DateTimeInterface::ATOM);
        }

        return new JsonResponse($payload, Response::HTTP_OK, [
            // Status changes as the boss acts; never worth caching.
            'Cache-Control' => 'no-store',
        ]);
    }
}
