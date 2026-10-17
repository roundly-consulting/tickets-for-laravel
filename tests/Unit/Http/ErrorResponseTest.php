<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use RoundlyConsulting\Comments\Exceptions\CommentRejectedException;
use RoundlyConsulting\Comments\Exceptions\CommentsLockedException;
use RoundlyConsulting\Comments\Exceptions\InvalidCommentBodyException;
use RoundlyConsulting\Comments\Exceptions\UnauthorizedCommentActionException;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Exceptions\ActivityIsAppendOnlyException;
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
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Exceptions\InvalidWatcherException;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketAttachmentNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Exceptions\TicketCommentNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketRecordNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketTransitionException;
use RoundlyConsulting\Tickets\Http\ErrorResponse;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The error mapping, exception by exception — the plan's §5.7 status column plus the codes the
 * API clients switch on.
 */
it('maps each exception to its status and code', function (Closure $exception, int $status, string $code): void {
    $error = ErrorResponse::from($exception());

    expect($error?->status)->toBe($status)
        ->and($error?->code)->toBe($code)
        ->and($error?->toResponse()->getData(true))->toMatchArray(['code' => $code]);
})->with([
    'ticket not found' => [fn () => TicketNotFoundException::forReference('TCK-1'), 404, 'ticket_not_found'],
    'comment not found' => [fn () => TicketCommentNotFoundException::missing(new Ticket(['reference' => 'TCK-1']), 5), 404, 'comment_not_found'],
    'attachment not found' => [fn () => TicketAttachmentNotFoundException::on(new Ticket(['reference' => 'TCK-1']), 'x'), 404, 'attachment_not_found'],
    'link not found' => [fn () => TicketRecordNotFoundException::link(new Ticket(['reference' => 'TCK-1']), 5), 404, 'link_not_found'],
    'category not found' => [fn () => TicketRecordNotFoundException::category(5), 404, 'category_not_found'],
    'field not found' => [fn () => TicketRecordNotFoundException::field(5), 404, 'field_not_found'],
    'tag not found' => [fn () => TicketRecordNotFoundException::tag(5), 404, 'tag_not_found'],
    'closed' => [fn () => TicketClosedException::for(new Ticket(['reference' => 'TCK-1'])), 409, 'ticket_closed'],
    'trashed' => [fn () => TicketTrashedException::for(new Ticket(['reference' => 'TCK-1'])), 409, 'ticket_trashed'],
    'lifecycle trashed' => [fn () => new SubjectTrashedException('Trashed.'), 409, 'ticket_trashed'],
    'field type locked' => [fn () => FieldTypeLockedException::type('size'), 409, 'field_type_locked'],
    'duplicate reference' => [fn () => DuplicateTicketReferenceException::for('SUP-1'), 409, 'duplicate_reference'],
    'access denied' => [fn () => TicketAccessDeniedException::notAnAgent(new Ticket(['reference' => 'TCK-1'])), 403, 'access_denied'],
    'guest link' => [fn () => GuestAccessDeniedException::invalidLink(), 403, 'guest_link_invalid'],
    'unknown field' => [fn () => FieldNotEditableException::unknown('nope'), 422, 'unknown_field'],
    'field not editable' => [fn () => FieldNotEditableException::notEditable('root_cause'), 422, 'field_not_editable'],
    'unknown requester type' => [fn () => UnknownMorphAliasException::alias('requesters', 'x'), 422, 'unknown_requester_type'],
    'unknown assignee type' => [fn () => UnknownMorphAliasException::missing('assignables', 'user', 5), 422, 'unknown_assignee_type'],
    'unknown watcher type' => [fn () => UnknownMorphAliasException::alias('watchables', 'x'), 422, 'unknown_watcher_type'],
    'unknown project type' => [fn () => UnknownMorphAliasException::alias('projects', 'x'), 422, 'unknown_project_type'],
    'unknown kind' => [fn () => UnknownMorphAliasException::unknownKind('things'), 422, 'unknown_type'],
    'unknown filter field' => [fn () => UnknownTicketFieldException::unknown('nope'), 422, 'unknown_field'],
    'unknown transition' => [fn () => UnknownTicketTransitionException::named('teleport'), 422, 'unknown_transition'],
    'invalid assignee' => [fn () => InvalidAssigneeException::noActor(), 422, 'invalid_assignee'],
    'invalid link' => [fn () => InvalidTicketLinkException::cycle(LinkType::Blocks), 422, 'invalid_link'],
    'invalid merge' => [fn () => InvalidMergeException::same(), 422, 'invalid_merge'],
    'invalid category' => [fn () => InvalidCategoryException::cycle(), 422, 'invalid_category'],
    'invalid field' => [fn () => InvalidFieldException::invalidKey('X'), 422, 'invalid_field'],
    'invalid tag' => [fn () => InvalidTagException::invalidName(), 422, 'invalid_tag'],
    'invalid watcher' => [fn () => InvalidWatcherException::noActor(), 422, 'invalid_watcher'],
    'invalid project' => [fn () => InvalidProjectException::invalidPrefix('x'), 422, 'invalid_project'],
    'comment rejected' => [fn () => CommentRejectedException::blocked(), 422, 'comment_rejected'],
    'comment body' => [fn () => InvalidCommentBodyException::empty(), 422, 'invalid_comment_body'],
    'comments locked' => [fn () => new CommentsLockedException('Locked.'), 409, 'ticket_closed'],
    'comment authorization' => [fn () => new UnauthorizedCommentActionException('No.'), 403, 'forbidden'],
    'a policy denial' => [fn () => new AuthorizationException, 403, 'forbidden'],
    'a policy 404' => [fn () => (new AuthorizationException('Gone.', 'ticket_not_found'))->withStatus(404), 404, 'ticket_not_found'],
    'a policy 409' => [function () {
        try {
            Response::denyWithStatus(409, 'Closed.', 'ticket_closed')->authorize();
        } catch (AuthorizationException $exception) {
            return $exception;
        }
    }, 409, 'ticket_closed'],
]);

it('gives an unnamed policy denial the translated message', function (): void {
    app()->setLocale('sk');

    expect(ErrorResponse::from(new AuthorizationException)?->message)->toBe('Na túto akciu nemáte oprávnenie.')
        ->and(ErrorResponse::from(new AuthorizationException('Custom.'))?->message)->toBe('Custom.');
});

it('answers a refused transition with its denials, and 429 with Retry-After when it can be retried', function (): void {
    $denied = TransitionDeniedException::because(Decision::deny(Denial::of('reason_required')));
    $limited = TransitionDeniedException::because(Decision::deny(Denial::of('rate_limited', retryAfter: now()->addMinute()->toImmutable())));

    $first = ErrorResponse::from($denied);
    $second = ErrorResponse::from($limited);

    expect($first?->status)->toBe(422)
        ->and($first?->code)->toBe('ticket_transition_denied')
        ->and($first?->detail['denials'][0]['code'] ?? null)->toBe('reason_required')
        ->and($first?->headers)->toBe([])
        ->and($second?->status)->toBe(429)
        ->and((int) ($second?->headers['Retry-After'] ?? 0))->toBeGreaterThan(50);
});

it('leaves configuration errors and anything foreign to the host', function (Closure $exception): void {
    expect(ErrorResponse::from($exception()))->toBeNull();
})->with([
    'tickets configuration' => [fn () => InvalidTicketsConfigurationException::invalid('tickets.x', 'y')],
    'a broken lifecycle' => [fn () => InvalidTicketLifecycleException::notADefinition('X')],
    'append-only' => [fn () => ActivityIsAppendOnlyException::make()],
    'anything else' => [fn () => new RuntimeException('Boom.')],
]);
