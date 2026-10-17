<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Attributes\Contracts\HasAttributes as HasAttributesContract;
use RoundlyConsulting\Attributes\Traits\HasAttributes as HasCustomFields;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Traits\HasComments;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * The ticket composes four foreign traits — lifecycle, comments, attributes (as
 * HasCustomFields) and media — and none shadows another, Eloquent's own HasAttributes, or the
 * package's conveniences. The reserved-name map pins who declares each method.
 */
describe('ticket_trait_composition', function (): void {
    it('declares every reserved method on the trait that owns it', function (string $trait, array $methods): void {
        foreach ($methods as $method) {
            $declared = (new ReflectionMethod(Ticket::class, $method))->getFileName();

            expect($declared)->toBe((new ReflectionClass($trait))->getFileName(), "{$method}() must come from ".class_basename($trait));
        }
    })->with([
        'lifecycle' => [HasLifecycle::class, ['lifecycle', 'lifecycleStates', 'lifecycleHistory', 'lifecycleLatestTransitions', 'lifecycleSchedules', 'transition', 'transitionTo', 'canTransition', 'canTransitionTo', 'scopeWhereState', 'scopeWhereNotState', 'scopeWhereFrozen', 'scopeWithLifecycle']],
        'comments' => [HasComments::class, ['comments', 'approvedComments', 'threadedComments', 'loadCommentCount', 'scopeWithCommentCounts', 'commentsLocked']],
        'attributes' => [HasCustomFields::class, ['attachedAttributes', 'attributeHistory', 'history', 'attributes', 'attr', 'attributeInt', 'attachAttribute', 'detachAttribute', 'syncAttributes', 'scopeWhereAttribute', 'scopeWhereAttributeBetween', 'scopeOrderByAttribute']],
        'media' => [InteractsWithMedia::class, ['media', 'addMedia', 'addMediaBucket', 'resolveMediaBucket', 'getMedia', 'getFirstMedia', 'hasMedia', 'attachMedia', 'clearMediaBucket']],
        'tickets' => [Ticket::class, ['reply', 'note', 'assignTo', 'activities', 'attributeDefinitions', 'registerMediaBuckets', 'lifecycleDefinitions', 'scopeWhereField', 'scopeWhereFieldBetween', 'priority', 'statusCategory', 'isUnresolved', 'isResolved', 'isClosed', 'isOverdue', 'isMerged', 'isAssigned', 'meta', 'hasMeta']],
    ]);

    it('implements every contract the traits need', function (): void {
        expect(new Ticket)
            ->toBeInstanceOf(LifecycleSubject::class)
            ->toBeInstanceOf(HasAttributesContract::class)
            ->toBeInstanceOf(HasMedia::class);
    });

    it('keeps Eloquent\'s own attribute API intact next to the attributes trait', function (): void {
        $ticket = new Ticket(['subject' => 'Plain attribute']);

        expect($ticket->getAttribute('subject'))->toBe('Plain attribute')
            ->and($ticket->getAttributes())->toBe(['subject' => 'Plain attribute'])
            ->and($ticket->hasAttribute('subject'))->toBeTrue();
    });

    it('runs all four on one instance: a transition, a comment, a custom field and a file', function (): void {
        Storage::fake('local');
        defineAgents();
        Tickets::fields()->make('asset_tag', FieldType::Text)->define();
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $ticket = Tickets::make()->subject('Composed')->requester(User::query()->create())->asSystem()->create();

        Tickets::for($ticket)->by($agent)->start();
        $ticket->reply('All four at once.', $agent);
        Tickets::for($ticket)->asSystem()->fields()->set('asset_tag', 'PRN-7');
        Tickets::for($ticket)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('a.txt', 'Some plain text.'));

        $fresh = $ticket->fresh();

        expect($fresh?->status)->toBe(TicketStatus::InProgress)
            ->and(Comments::for($ticket)->get())->toHaveCount(1)
            ->and($fresh?->getAttachedAttributeValue('asset_tag'))->toBe('PRN-7')
            ->and($fresh?->getMedia('attachments'))->toHaveCount(1)
            ->and($fresh?->lifecycleHistory()->count())->toBe(2);
    });
});
