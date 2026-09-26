<?php

declare(strict_types=1);

namespace App\Alerts;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /ask/{id} page — where the boss answers an interactive request.
 *
 * One page, no JavaScript, no login: the id in the URL is the capability
 * (128 random bits, single-use, expiring with the request). GET renders
 * one of four states — form, answered, expired, not found; POST records
 * the answer and redirects back to GET (303) so a reload never
 * re-submits.
 *
 * Deliberately outside the tool pipeline: this is a human surface, not a
 * tool, and it must not appear in the mcp_invocation log. The harness
 * surface is GET /inputs/{id} ({@see InputStatusController}) for the same
 * reason.
 *
 * CSRF notes: the form is session-free and carries no CSRF token, by
 * design (docs/design/ALERTS.md). The id *is* the capability — a
 * cross-site request that does not know the id has nothing to submit to,
 * and knowing the id already grants the ability to answer. That is the
 * same trust model as an ntfy topic name, and it keeps the page free of
 * session state and extra dependencies.
 */
final class AskController extends AbstractController
{
    /** Free-text answers are capped so one submission cannot stuff the store. */
    private const MAX_ANSWER_CHARS = 4000;

    public function __construct(
        private PendingRequestStore $store,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/ask/{id}', name: 'alerts_ask', methods: ['GET'])]
    public function show(string $id): Response
    {
        $request = $this->store->find($id, $this->clock->now());

        if (null === $request) {
            return $this->page('not_found', null, Response::HTTP_NOT_FOUND);
        }

        return match ($request->status) {
            RequestStatus::Expired => $this->page('expired', $request, Response::HTTP_GONE),
            RequestStatus::Answered => $this->page('answered', $request),
            RequestStatus::Pending => $this->page('form', $request),
        };
    }

    #[Route('/ask/{id}', name: 'alerts_ask_submit', methods: ['POST'])]
    public function submit(string $id, Request $httpRequest): Response
    {
        $now = $this->clock->now();
        $pending = $this->store->find($id, $now);

        if (null === $pending) {
            return $this->page('not_found', null, Response::HTTP_NOT_FOUND);
        }

        if (RequestStatus::Answered === $pending->status) {
            return $this->page('answered', $pending, Response::HTTP_CONFLICT);
        }

        if (RequestStatus::Expired === $pending->status) {
            return $this->page('expired', $pending, Response::HTTP_GONE);
        }

        $raw = $httpRequest->request->get('answer');
        [$answer, $error] = $this->validateAnswer($pending->type, \is_string($raw) ? $raw : '');

        if (null !== $error) {
            return $this->page('form', $pending, Response::HTTP_UNPROCESSABLE_ENTITY, $error, \is_string($raw) ? $raw : '');
        }
        \assert(null !== $answer);

        // The store re-checks state: between the read above and this call
        // somebody else may have answered (first answer wins), or the
        // request may have expired.
        return match ($this->store->answer($id, $answer, $now)) {
            AnswerResult::Answered => $this->redirectToRoute('alerts_ask', ['id' => $id], Response::HTTP_SEE_OTHER),
            AnswerResult::AlreadyAnswered => $this->page('answered', $pending, Response::HTTP_CONFLICT),
            AnswerResult::Expired => $this->page('expired', $pending, Response::HTTP_GONE),
            AnswerResult::NotFound => $this->page('not_found', null, Response::HTTP_NOT_FOUND),
        };
    }

    /**
     * @return array{0: string|bool|null, 1: string|null} validated answer and error (mutually exclusive)
     */
    private function validateAnswer(RequestType $type, string $raw): array
    {
        if (RequestType::Confirm === $type) {
            return match ($raw) {
                'yes' => [true, null],
                'no' => [false, null],
                default => [null, 'Please choose one of the buttons below.'],
            };
        }

        $trimmed = trim($raw);

        if ('' === $trimmed) {
            return [null, 'Please type an answer before submitting.'];
        }

        if (mb_strlen($trimmed) > self::MAX_ANSWER_CHARS) {
            return [null, \sprintf('Answers are limited to %d characters.', self::MAX_ANSWER_CHARS)];
        }

        return [$trimmed, null];
    }

    /**
     * @param string $state     one of: form, answered, expired, not_found
     * @param string $submitted the raw submission, echoed back into the textarea on a validation error
     */
    private function page(
        string $state,
        ?PendingRequest $pending,
        int $status = Response::HTTP_OK,
        ?string $error = null,
        string $submitted = '',
    ): Response {
        return $this->render('alerts/ask.html.twig', [
            'state' => $state,
            'pending' => $pending,
            'error' => $error,
            'submitted' => $submitted,
        ], new Response(status: $status));
    }
}
