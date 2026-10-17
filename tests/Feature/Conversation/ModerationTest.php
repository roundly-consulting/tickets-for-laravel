<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Events\CommentMentioned;
use RoundlyConsulting\Comments\Exceptions\CommentRejectedException;
use RoundlyConsulting\Comments\Exceptions\UnauthorizedCommentActionException;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Comments\Models\CommentMention;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketMentioned;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\Agents;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use RoundlyConsulting\Tickets\Tests\Fixtures\MentionResolver;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\LegacyComment;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\Policies\RestrictiveCommentPolicy;
use RoundlyConsulting\Tickets\Tests\Fixtures\Policies\TicketFriendlyCommentPolicy;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;

beforeEach(fn () => defineAgents());

function modTicket(?User $requester = null): Ticket
{
    return Tickets::make()->subject('VPN drops')->requester($requester ?? User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

/**
 * @return array{ok: bool, checks: list<array{section: string, code: string, level: string, message: string}>}
 */
function conversationDoctor(): array
{
    Artisan::call('tickets:doctor', ['--json' => true]);

    /** @var array{ok: bool, checks: list<array{section: string, code: string, level: string, message: string}>} $output */
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $output['checks'] = array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === 'conversation'));

    return $output;
}

describe('comments_moderation_interplay', function (): void {
    it('ends a ticket reply approved under the host\'s require_approval', function (): void {
        config()->set('comments.require_approval', true);
        $requester = User::query()->create(['name' => 'Ada']);
        $ticket = modTicket($requester);

        $reply = Tickets::for($ticket)->by($requester)->reply('Still dropping.');
        $note = Tickets::for($ticket)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->note('Router firmware.');

        expect($reply->fresh()?->status)->toBe(CommentStatus::Approved)
            ->and($note->fresh()?->status)->toBe(CommentStatus::Approved);
    });

    it('rolls the whole reply back when the host blocklist rejects it', function (): void {
        config()->set('comments.blocklist', ['viagra']);
        config()->set('comments.blocklist_action', 'reject');
        $requester = User::query()->create(['name' => 'Ada']);
        $ticket = modTicket($requester);
        Tickets::for($ticket)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->wait();
        $activities = TicketActivity::query()->count();

        expect(fn () => Tickets::for($ticket)->by($requester)->reply('Buy viagra now'))->toThrow(CommentRejectedException::class)
            ->and(Comment::query()->count())->toBe(0)
            ->and(TicketActivity::query()->count())->toBe($activities)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Pending);
    });

    it('is refused by a restrictive host comment policy under comments.authorization', function (): void {
        config()->set('comments.authorization', true);
        Gate::policy(Comment::class, RestrictiveCommentPolicy::class);
        $requester = User::query()->create(['name' => 'Ada']);
        $ticket = modTicket($requester);

        expect(fn () => Tickets::for($ticket)->by($requester)->reply('Hello?'))->toThrow(UnauthorizedCommentActionException::class)
            ->and(Comment::query()->count())->toBe(0)
            ->and($ticket->activities()->where('type', ActivityType::Replied->value)->count())->toBe(0);
    });

    it('works end to end with the documented ticket-friendly host policy', function (): void {
        config()->set('comments.authorization', true);
        config()->set('comments.require_approval', true);
        Gate::policy(Comment::class, TicketFriendlyCommentPolicy::class);
        $requester = User::query()->create(['name' => 'Ada']);
        $ticket = modTicket($requester);
        $document = Document::query()->create();

        $reply = Tickets::for($ticket)->by($requester)->reply('Hello?');
        $note = Tickets::for($ticket)->asSystem()->note('Imported.');
        Tickets::for($ticket)->by(User::query()->create(['name' => 'Grace', 'is_agent' => true]))->resolve()->close();

        expect($reply->fresh()?->status)->toBe(CommentStatus::Approved)
            ->and($note->exists)->toBeTrue()
            ->and(Comments::isLocked($ticket))->toBeTrue()
            ->and(fn () => Comments::on($document)->body('Not a ticket.')->post())->toThrow(UnauthorizedCommentActionException::class);
    });

    it('warns in the doctor while comments.authorization is on', function (): void {
        expect(array_column(conversationDoctor()['checks'], 'level', 'code'))->toBe(['meta' => 'ok']);

        config()->set('comments.authorization', true);

        $checks = conversationDoctor()['checks'];

        expect(array_column($checks, 'level', 'code'))->toBe(['meta' => 'ok', 'comments_authorization' => 'warning'])
            ->and($checks[1]['message'])->toContain('CommentPolicy');
    });

    it('fails the doctor when the comments table has no meta column', function (): void {
        config()->set('comments.model', LegacyComment::class);

        $output = conversationDoctor();

        expect($output['ok'])->toBeFalse()
            ->and(array_column($output['checks'], 'level', 'code'))->toBe(['meta' => 'error'])
            ->and($output['checks'][0]['message'])->toContain('comments-for-laravel 1.1');
    });
});

describe('mentions', function (): void {
    beforeEach(fn () => config()->set('comments.mention_resolver', MentionResolver::class));

    it('passes a mention on only to someone who may read the comment', function (Closure $post, array $expected): void {
        Event::fake([TicketMentioned::class]);
        $requester = User::query()->create(['name' => 'Ada']);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        User::query()->create(['name' => 'Stranger']);
        $watcher = User::query()->create(['name' => 'Cc']);
        $ticket = modTicket($requester);
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);

        $comment = $post($ticket, $agent);

        $mentioned = [];
        Event::assertDispatched(TicketMentioned::class, static function (TicketMentioned $event) use (&$mentioned, $comment, $ticket): bool {
            $mentioned[] = $event->mentioned->getAttribute('name');

            return $event->comment->is($comment) && $event->ticket->is($ticket);
        });

        sort($mentioned);

        expect($mentioned)->toBe($expected);
    })->with([
        'a note reaches the mentioned agent only' => [
            fn (Ticket $ticket, User $agent) => Tickets::for($ticket)->by($agent)->note('@Grace @Ada @Cc @Stranger have a look.'),
            ['Grace'],
        ],
        'a public reply reaches whoever can view the ticket' => [
            fn (Ticket $ticket, User $agent) => Tickets::for($ticket)->by($agent)->reply('@Grace @Ada @Cc @Stranger fixed.'),
            ['Ada', 'Cc', 'Grace'],
        ],
    ]);

    it('passes a note\'s mention only to an agent the agent scope lets work on the ticket', function (): void {
        Event::fake([TicketMentioned::class]);
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $ticket = modTicket();

        // `Scoped` works on SUP tickets only; this one has no project, so they may not open it.
        Tickets::for($ticket)->by($agent)->note('@Scoped @Grace a look, please.');

        $mentioned = [];
        Event::assertDispatched(TicketMentioned::class, static function (TicketMentioned $event) use (&$mentioned): bool {
            $mentioned[] = $event->mentioned->getAttribute('name');

            return true;
        });

        expect($mentioned)->toBe(['Grace']);
    });

    it('drops a watcher\'s mention once watchers may not view', function (): void {
        Event::fake([TicketMentioned::class]);
        config()->set('tickets.watchers.can_view', 'off');
        $agent = User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $watcher = User::query()->create(['name' => 'Cc']);
        $ticket = modTicket();
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);

        Tickets::for($ticket)->by($agent)->reply('@Cc fixed.');

        Event::assertNotDispatched(TicketMentioned::class);
    });

    it('holds the mentions of a held reply', function (): void {
        Event::fake([TicketMentioned::class]);
        config()->set('comments.blocklist', ['scam']);
        config()->set('comments.blocklist_action', 'pending');
        User::query()->create(['name' => 'Grace', 'is_agent' => true]);
        $requester = User::query()->create(['name' => 'Ada']);

        Tickets::for(modTicket($requester))->by($requester)->reply('@Grace this is a scam.');

        Event::assertNotDispatched(TicketMentioned::class);
    });

    it('leaves mentions on other subjects and unresolved handles alone', function (): void {
        Event::fake([TicketMentioned::class]);
        User::query()->create(['name' => 'Grace', 'is_agent' => true]);

        Comments::on(Document::query()->create())->body('@Grace look at this document.')->post();
        Tickets::for(modTicket())->asSystem()->reply('@Nobody is here.');

        Event::assertNotDispatched(TicketMentioned::class);
    });

    it('ignores a mention whose person is gone', function (): void {
        Event::fake([TicketMentioned::class]);
        $comment = Comments::on(modTicket())->body('Ping.')->post();
        $mention = CommentMention::query()->create([
            'comment_id' => $comment->getKey(),
            'handle' => 'Ghost',
            'mentionable_type' => (new User)->getMorphClass(),
            'mentionable_id' => 999,
        ]);

        event(new CommentMentioned($mention));

        Event::assertNotDispatched(TicketMentioned::class);
    });

    it('still lets the host listen to comments\' own CommentMentioned', function (): void {
        Event::fake([CommentMentioned::class]);
        User::query()->create(['name' => 'Grace', 'is_agent' => true]);

        Tickets::for(modTicket())->asSystem()->note('@Grace psst.');

        Event::assertDispatched(CommentMentioned::class);
    });
});

describe('who may see a ticket', function (): void {
    it('never counts nobody, or a model that cannot log in, as an agent', function (): void {
        $ticket = modTicket();
        $team = Team::query()->create(['name' => 'Hardware']);
        Gate::before(static fn (): bool => true);

        expect(Agents::is(null))->toBeFalse()
            ->and(Agents::is($team))->toBeFalse()
            ->and(TicketVisibility::canView(null, $ticket))->toBeFalse()
            ->and(TicketVisibility::canView($team, $ticket))->toBeFalse()
            ->and(Agents::is(User::query()->create(['name' => 'Anyone'])))->toBeTrue();
    });

    it('fails closed when the host never defined the agent ability', function (): void {
        config()->set('tickets.authorization.abilities.agent', 'tickets.undefined-agent');

        expect(Agents::is(User::query()->create(['name' => 'Grace', 'is_agent' => true])))->toBeFalse();
    });
});
