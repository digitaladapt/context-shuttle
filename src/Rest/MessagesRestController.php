<?php

declare(strict_types=1);

namespace App\Rest;

use App\Messages\ConversationName;
use App\Messages\ConversationNameException;
use App\Messages\MessageBody;
use App\Messages\MessageStore;
use App\Messages\Sender;
use App\Tool\Messages\MessagesTool;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST surface for the message bus: GET /messages and POST /messages.
 *
 * The tools (`send_message`, `get_messages`) are the model-facing half of
 * the family; this is the system-facing half — the harness or human that
 * wants to speak into a conversation, or poll one, without being an MCP
 * client. Both halves sit on the same {@see MessageStore} with the same
 * validation, so a cursor obtained here pages in `get_messages` and vice
 * versa; that interop is the design note's requirement, and it holds by
 * construction because there is one store and one id space.
 *
 * Like the tool surface (`ToolRestController`), REST here is
 * pre-authenticated by its caller: no session, no key check. The POST
 * sender is a claim — the same honest hole the tool documents — and the
 * default (`operator`) is the name for "whoever holds this endpoint".
 */
final class MessagesRestController
{
    public function __construct(
        private readonly MessageStore $store,
        private readonly string $actor,
    ) {
    }

    /**
     * GET /messages?conversation=…&since=…&limit=… — a page of a
     * conversation, oldest first, after the cursor.
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $conversation = ConversationName::from($request->query->get('conversation'));
        } catch (ConversationNameException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $since = max(0, (int) $request->query->get('since', 0));
        $limit = min(MessagesTool::MAX_LIMIT, max(1, (int) $request->query->get('limit', 50)));

        return new JsonResponse($this->store->after($conversation, $since, $limit));
    }

    /**
     * POST /messages {body, conversation?, sender?} — append to a
     * conversation as its caller's named sender.
     */
    public function post(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!\is_array($payload) || !\array_key_exists('body', $payload)) {
            return new JsonResponse(
                ['error' => "A JSON body with a 'body' string is required."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!\is_string($payload['body'])) {
            return new JsonResponse(
                ['error' => "'body' must be a string."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $conversation = ConversationName::from($payload['conversation'] ?? null);
            $normalized = MessageBody::normalize($payload['body']);
        } catch (ConversationNameException|InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sender = Sender::from(\is_string($payload['sender'] ?? null) ? $payload['sender'] : null, 'operator')->value;

        $row = $this->store->append($conversation, $sender, $normalized['body']);

        return new JsonResponse([
            'stored' => true,
            'id' => $row['id'],
            'conversation' => $row['conversation'],
            'sender' => $row['sender'],
            'created_at' => $row['created_at'],
            'truncated' => $normalized['truncated'],
        ], Response::HTTP_CREATED);
    }
}
