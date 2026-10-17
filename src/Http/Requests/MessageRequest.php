<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * A message without a transition: an internal note, or a guest's reply.
 *
 * @internal
 */
final class MessageRequest extends ConversationRequest {}
