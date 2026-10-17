<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Comments\Facades\Comments;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Tickets\DataTransferObjects\MergeResult;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Events\TicketMerged;
use RoundlyConsulting\Tickets\Exceptions\InvalidMergeException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Support\RecordsLocks;

beforeEach(fn () => defineAgents());

function mergeTicket(?User $requester = null, ?Project $project = null): Ticket
{
    return Tickets::make()->subject('Email bounces')->requester($requester ?? User::query()->create(['name' => 'Ada']))->project($project)->asSystem()->create();
}

function mergeAgent(): User
{
    return User::query()->where('name', 'Grace')->first() ?? User::query()->create(['name' => 'Grace', 'is_agent' => true]);
}

describe('merge', function (): void {
    it('moves the conversation, watchers, tags and attachments and dismisses the source', function (): void {
        Storage::fake('local');
        Event::fake([TicketMerged::class]);
        $ada = User::query()->create(['name' => 'Ada']);
        $agent = mergeAgent();
        $cc = User::query()->create(['name' => 'Cc']);
        $source = mergeTicket($ada);
        $target = mergeTicket($ada);
        $reply = Tickets::for($source)->by($ada)->reply('It bounces since Monday.');
        $note = Tickets::for($source)->by($agent)->note('SPF record is wrong.');
        Tickets::for($source)->asSystem()->watchers()->add($cc);
        Tickets::for($source)->asSystem()->tags()->attach(['email', 'dns']);
        Tickets::for($target)->asSystem()->tags()->attach('email');
        $file = Tickets::for($source)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('bounce.txt', 'Delivery failed.'));

        $result = Tickets::for($source)->by($agent)->mergeInto($target, reason: 'Same incident');

        $source->refresh();
        $sourceThread = Tickets::for($source)->thread(internal: true);
        $targetThread = Tickets::for($target)->thread(internal: true);

        expect($result)->toBeInstanceOf(MergeResult::class)
            ->and($result->source)->toBe($source)
            ->and($result->target->is($target))->toBeTrue()
            ->and($result->comments)->toBe(2)
            ->and($result->attachments)->toBe(1)
            ->and($targetThread->modelKeys())->toContain($reply->id, $note->id)
            ->and($targetThread->last()?->comment)->toBe('Ticket TCK-1 was merged into this ticket.')
            ->and(TicketComments::isInternal($targetThread->last()))->toBeTrue()
            ->and(TicketComments::role($targetThread->last()))->toBe(AuthorRole::System)
            ->and($sourceThread->pluck('comment')->all())->toBe(['This ticket was merged into TCK-2.'])
            ->and(Tickets::for($target)->watchers()->has($cc))->toBeTrue()
            ->and(Tickets::for($target)->tags()->all()->pluck('slug')->sort()->values()->all())->toBe(['dns', 'email'])
            ->and(Tickets::for($target)->attachments()->all()->pluck('uuid')->all())->toBe([$file->uuid])
            ->and(Tickets::for($source)->attachments()->all())->toHaveCount(0)
            ->and(TicketLink::query()->where('type', LinkType::Duplicates->value)->sole()->ticket_id)->toBe($source->id)
            ->and($source->merged_into_id)->toBe($target->id)
            ->and($source->mergedInto?->is($target))->toBeTrue()
            ->and($source->guest_access_version)->toBe(2)
            ->and($source->status)->toBe(TicketStatus::Closed)
            ->and(Comments::isLocked($source))->toBeTrue()
            ->and($source->lifecycleHistory()->latest('id')->first()?->reason)->toBe('merged:TCK-2')
            ->and(keySorted($source->activities()->where('type', ActivityType::Merged->value)->sole()->properties))
            ->toBe(['attachments' => 1, 'comments' => 2, 'into' => 'TCK-2', 'reason' => 'Same incident'])
            ->and(keySorted($target->activities()->where('type', ActivityType::MergedFrom->value)->sole()->properties))->toBe(['comments' => 2, 'from' => 'TCK-1'])
            ->and(Ticket::query()->merged()->pluck('id')->all())->toBe([$source->id]);

        Event::assertDispatched(TicketMerged::class, static fn (TicketMerged $event): bool => $event->source->is($source)
            && $event->target->is($target) && $event->result->comments === 2 && $event->actor?->is($agent) === true);
    });

    it('merges a resolved source and cancels its auto-close', function (): void {
        $agent = mergeAgent();
        $source = mergeTicket();
        $target = mergeTicket();
        Tickets::for($source)->by($agent)->resolve();

        expect($source->lifecycleSchedules()->where('status', ScheduleStatus::Pending->value)->count())->toBe(1);

        Tickets::for($source)->by($agent)->mergeInto('TCK-2');

        expect($source->fresh()?->status)->toBe(TicketStatus::Closed)
            ->and($source->lifecycleSchedules()->where('status', ScheduleStatus::Pending->value)->count())->toBe(0);
    });

    it('keeps the attachments on the source with move_attachments off', function (): void {
        Storage::fake('local');
        config()->set('tickets.merge.move_attachments', 'off');
        $source = mergeTicket();
        $target = mergeTicket();
        Tickets::for($source)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('a.txt', 'Log.'));

        $result = Tickets::for($source)->by(mergeAgent())->mergeInto($target);

        expect($result->attachments)->toBe(0)
            ->and(Tickets::for($source)->attachments()->all())->toHaveCount(1);
    });

    it('leaves out a source tag the target\'s project may not carry', function (): void {
        config()->set('tickets.merge.cross_project', 'on');
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $source = mergeTicket(project: $sup);
        $target = mergeTicket();
        Tickets::for($source)->asSystem()->tags()->attach(['vpn']);

        Tickets::for($source)->by(mergeAgent())->mergeInto($target);

        expect(Tickets::for($target)->tags()->all())->toHaveCount(0);
    });
});

describe('merge_refusals', function (): void {
    it('refuses the same ticket', function (): void {
        $ticket = mergeTicket();

        Tickets::for($ticket)->by(mergeAgent())->mergeInto($ticket);
    })->throws(InvalidMergeException::class, 'itself');

    it('refuses a closed or merged source and target', function (Closure $arrange, string $message): void {
        $agent = mergeAgent();
        [$source, $target, $final] = [mergeTicket(), mergeTicket(), mergeTicket()];

        $arrange($source, $target, $final, $agent);

        expect(fn () => Tickets::for($source)->by($agent)->mergeInto($target))->toThrow(InvalidMergeException::class, $message);
    })->with([
        'closed source' => [fn (Ticket $source, Ticket $target, Ticket $final, User $agent) => Tickets::for($source)->by($agent)->resolve()->close(), '[TCK-1] is closed'],
        'merged source' => [fn (Ticket $source, Ticket $target, Ticket $final, User $agent) => Tickets::for($source)->by($agent)->mergeInto($final), 'already merged'],
        'closed target' => [fn (Ticket $source, Ticket $target, Ticket $final, User $agent) => Tickets::for($target)->by($agent)->resolve()->close(), '[TCK-2] is closed'],
        'merged target names the final one' => [fn (Ticket $source, Ticket $target, Ticket $final, User $agent) => Tickets::for($target)->by($agent)->mergeInto($final), 'merged into [TCK-3]'],
    ]);

    it('follows a merge chain to its end', function (): void {
        $agent = mergeAgent();
        [$source, $a, $b, $c] = [mergeTicket(), mergeTicket(), mergeTicket(), mergeTicket()];
        Tickets::for($b)->by($agent)->mergeInto($c);
        Tickets::for($a)->by($agent)->dismiss('dup');
        $a->refresh()->forceFill(['merged_into_id' => $b->id])->saveQuietly();

        expect(fn () => Tickets::for($source)->by($agent)->mergeInto($a->fresh() ?? $a))->toThrow(InvalidMergeException::class, '[TCK-4]');
    });

    it('stops following a broken merge chain', function (): void {
        [$source, $a, $b] = [mergeTicket(), mergeTicket(), mergeTicket()];
        Ticket::query()->whereKey($a->id)->update(['merged_into_id' => $b->id]);
        Ticket::query()->whereKey($b->id)->update(['merged_into_id' => $a->id]);

        expect(fn () => Tickets::for($source)->asSystem()->mergeInto($a->fresh() ?? $a))->toThrow(InvalidMergeException::class, 'merged into');
    });

    it('refuses a cross-project merge unless switched on', function (): void {
        $sup = Project::query()->create(['name' => 'Helpdesk', 'key' => 'SUP']);
        $source = mergeTicket(project: $sup);
        $target = mergeTicket();

        expect(fn () => Tickets::for($source)->by(mergeAgent())->mergeInto($target))->toThrow(InvalidMergeException::class, 'projects');

        config()->set('tickets.merge.cross_project', 'on');

        expect(Tickets::for($source)->by(mergeAgent())->mergeInto($target)->target->is($target))->toBeTrue();
    });

    it('refuses an actor the merge ability does not allow', function (): void {
        $requester = User::query()->create(['name' => 'Ada']);
        $source = mergeTicket($requester);
        $target = mergeTicket($requester);

        expect(fn () => Tickets::for($source)->by($requester)->mergeInto($target))->toThrow(InvalidMergeException::class, 'may not')
            ->and($source->fresh()?->merged_into_id)->toBeNull();
    });

    it('lets the system merge without an actor', function (): void {
        $source = mergeTicket();

        expect(Tickets::for($source)->asSystem()->mergeInto(mergeTicket())->source->merged_into_id)->not->toBeNull();
    });

    it('locks both tickets in primary-key order, whichever is the source', function (): void {
        $low = mergeTicket();
        $high = mergeTicket();
        RecordsLocks::start();
        $keys = [];
        DB::listen(static function (QueryExecuted $query) use (&$keys): void {
            if (str_contains($query->sql, 'lock-') && str_contains($query->sql, '"tickets"')) {
                $keys[] = $query->bindings[0] ?? null;
            }
        });

        Tickets::for($high)->by(mergeAgent())->mergeInto($low);

        expect(RecordsLocks::tables()[0] ?? null)->toBe('tickets')
            ->and(array_slice($keys, 0, 2))->toBe([$low->id, $high->id]);
    })->skip(fn (): bool => ! RecordsLocks::available(), 'lock recording is a sqlite grammar');
});

describe('cross_requester_merge_shares_public_thread_only', function (): void {
    it('lets the source requester see the target\'s public thread, never its notes', function (): void {
        Event::fake([TicketMerged::class]);
        $ada = User::query()->create(['name' => 'Ada']);
        $bob = User::query()->create(['name' => 'Bob']);
        $agent = mergeAgent();
        $source = mergeTicket($ada);
        $target = mergeTicket($bob);
        $adaReply = Tickets::for($source)->by($ada)->reply('My mail bounces.');
        $bobReply = Tickets::for($target)->by($bob)->reply('Mine too.');
        $targetNote = Tickets::for($target)->by($agent)->note('Bob is on the legacy relay.');
        $sourceNote = Tickets::for($source)->by($agent)->note('Ada is on the new relay.');

        Tickets::for($source)->by($agent)->mergeInto($target);

        $public = Tickets::for($target)->thread()->modelKeys();

        expect(TicketVisibility::canView($ada, $target))->toBeTrue()
            ->and(TicketVisibility::canView($bob, $target))->toBeTrue()
            ->and(Tickets::for($target)->watchers()->has($ada))->toBeTrue()
            ->and($public)->toContain($adaReply->id, $bobReply->id)
            ->and($public)->not->toContain($targetNote->id)
            ->and($public)->not->toContain($sourceNote->id)
            ->and(Tickets::for($target)->thread()->pluck('comment')->all())->not->toContain('Ticket TCK-1 was merged into this ticket.')
            ->and(Comment::query()->whereKey($sourceNote->id)->value('commentable_id'))->toBe($target->id)
            ->and(TicketComments::role(Tickets::for($target)->by($ada)->reply('Thanks, both of us are fixed.')))->toBe(AuthorRole::Participant);

        Event::assertDispatched(TicketMerged::class, static fn (TicketMerged $event): bool => $event->source->requester?->is($ada) === true);
    });
});
