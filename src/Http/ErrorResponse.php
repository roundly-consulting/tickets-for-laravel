<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http;

use Illuminate\Auth\Access\AuthorizationException;
use RoundlyConsulting\Comments\Exceptions\CommentRejectedException;
use RoundlyConsulting\Comments\Exceptions\CommentsLockedException;
use RoundlyConsulting\Comments\Exceptions\InvalidCommentBodyException;
use RoundlyConsulting\Comments\Exceptions\UnauthorizedCommentActionException;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Tickets\Exceptions\DuplicateTicketReferenceException;
use RoundlyConsulting\Tickets\Exceptions\FieldNotEditableException;
use RoundlyConsulting\Tickets\Exceptions\FieldTypeLockedException;
use RoundlyConsulting\Tickets\Exceptions\GuestAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Exceptions\InvalidMergeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidProjectException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTagException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Exceptions\InvalidWatcherException;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketAttachmentNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Exceptions\TicketCommentNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketsException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketTransitionException;
use RoundlyConsulting\Tickets\Support\Messages;
use Throwable;

/**
 * The API's error mapping — in the package's controllers only, never the host's exception
 * handler: each package exception (and the lifecycle and comments ones the API can meet) becomes
 * `{message, code}` with its status. Anything else is not the API's to answer and is rethrown:
 * a validation error renders as Laravel's 422, a configuration error stays a 500.
 *
 * @internal
 */
final class ErrorResponse
{
    public static function from(Throwable $exception): ?ApiError
    {
        return match (true) {
            $exception instanceof AuthorizationException => self::authorization($exception),
            $exception instanceof TransitionDeniedException => self::transitionDenied($exception),
            // lifecycle's message names the subject's class and key: the client gets its own.
            $exception instanceof SubjectTrashedException => new ApiError(409, 'ticket_trashed', Messages::get('http.trashed')),
            $exception instanceof CommentRejectedException => new ApiError(422, 'comment_rejected', $exception->getMessage()),
            $exception instanceof InvalidCommentBodyException => new ApiError(422, 'invalid_comment_body', $exception->getMessage()),
            $exception instanceof CommentsLockedException => new ApiError(409, 'ticket_closed', $exception->getMessage()),
            $exception instanceof UnauthorizedCommentActionException => new ApiError(403, 'forbidden', $exception->getMessage()),
            $exception instanceof TicketsException => self::tickets($exception),
            default => null,
        };
    }

    /**
     * The one answer a guest link that does not work gets — expired, tampered, revoked, another
     * ticket's or another guest's alike.
     */
    public static function guestLinkInvalid(): ApiError
    {
        return new ApiError(403, 'guest_link_invalid', GuestAccessDeniedException::invalidLink()->getMessage());
    }

    private static function tickets(TicketsException $exception): ?ApiError
    {
        [$status, $code] = match (true) {
            $exception instanceof TicketNotFoundException => [404, 'ticket_not_found'],
            $exception instanceof TicketCommentNotFoundException => [404, 'comment_not_found'],
            $exception instanceof TicketAttachmentNotFoundException => [404, 'attachment_not_found'],
            $exception instanceof TicketRecordNotFoundException => [404, $exception->errorCode()],
            $exception instanceof TicketClosedException => [409, 'ticket_closed'],
            $exception instanceof TicketTrashedException => [409, 'ticket_trashed'],
            $exception instanceof FieldTypeLockedException => [409, 'field_type_locked'],
            $exception instanceof DuplicateTicketReferenceException => [409, 'duplicate_reference'],
            $exception instanceof TicketAccessDeniedException => [403, 'access_denied'],
            $exception instanceof GuestAccessDeniedException => [403, 'guest_link_invalid'],
            $exception instanceof FieldNotEditableException => [422, $exception->errorCode()],
            $exception instanceof UnknownMorphAliasException => [422, $exception->errorCode()],
            $exception instanceof UnknownTicketFieldException => [422, 'unknown_field'],
            $exception instanceof UnknownTicketTransitionException => [422, 'unknown_transition'],
            $exception instanceof InvalidAssigneeException => [422, 'invalid_assignee'],
            $exception instanceof InvalidTicketLinkException => [422, 'invalid_link'],
            $exception instanceof InvalidMergeException => [422, 'invalid_merge'],
            $exception instanceof InvalidCategoryException => [422, 'invalid_category'],
            $exception instanceof InvalidFieldException => [422, 'invalid_field'],
            $exception instanceof InvalidTagException => [422, 'invalid_tag'],
            $exception instanceof InvalidWatcherException => [422, 'invalid_watcher'],
            $exception instanceof InvalidProjectException => [422, 'invalid_project'],
            // Configuration and invariant errors are the host's to fix, never the client's.
            default => [null, null],
        };

        return $status === null ? null : new ApiError($status, $code, $exception->getMessage());
    }

    /**
     * A policy denial: its own status and code when the policy gave them (`denyAsNotFound`,
     * the 409 of a reply to a closed ticket), else 403 `forbidden`.
     */
    private static function authorization(AuthorizationException $exception): ApiError
    {
        // The policy's code rides on the exception: Laravel stores the Response code (a string)
        // as the exception code, or 0 when there is none.
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return new ApiError(
            $exception->status() ?? 403,
            $code === '0' ? 'forbidden' : $code,
            $message === (new AuthorizationException)->getMessage() ? Messages::get('http.forbidden') : $message,
        );
    }

    /**
     * 422 `ticket_transition_denied` with every denial — or 429 with `Retry-After` when the
     * denial says when a retry can succeed (a rate limit, a cooldown).
     */
    private static function transitionDenied(TransitionDeniedException $exception): ApiError
    {
        $retryAfter = $exception->retryAfterSeconds();

        return new ApiError(
            $retryAfter > 0 ? 429 : 422,
            'ticket_transition_denied',
            $exception->getMessage(),
            ['denials' => array_map(static fn (Denial $denial): array => $denial->toArray(), $exception->denials())],
            $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : [],
        );
    }
}
