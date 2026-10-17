<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles\HostLifecycle;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;
use RoundlyConsulting\Tickets\Tests\Fixtures\ProjectAgentScope;

beforeEach(fn () => apiAbilities());

/**
 * A spread of tickets every filter has something to tell apart in.
 *
 * @return array{agent: User, requester: User, team: Team, project: Project, parent: TicketCategory, child: TicketCategory}
 */
function indexWorld(): array
{
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    $agent = apiAgent();
    $requester = apiUser();
    $other = apiUser('Bob');
    $team = Team::query()->create(['name' => 'Hardware']);
    $project = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
    $parent = Tickets::categories()->make('Hardware')->create();
    $child = Tickets::categories()->make('Printers')->under($parent)->create();
    Tickets::fields()->make('size', FieldType::Number)->define();
    Tickets::fields()->make('secret', FieldType::Text)->encrypted()->define();

    $make = static fn (string $subject, User $by, array $extra = []): Ticket => Tickets::make()->subject($subject)->requester($by)->asSystem()
        ->category($extra['category'] ?? null)->priority($extra['priority'] ?? null)->project($extra['project'] ?? null)
        ->channel($extra['channel'] ?? null)->due($extra['due'] ?? null)->fields($extra['fields'] ?? [])->tags($extra['tags'] ?? [])->create();

    $a = $make('Paper jam on floor 3', $requester, ['category' => $parent, 'priority' => 'high', 'tags' => ['printer'], 'fields' => ['size' => 3], 'due' => CarbonImmutable::now()->subHour()]);
    CarbonImmutable::setTestNow('2026-10-18 12:00:00');
    $b = $make('VPN drops', $other, ['category' => $child, 'priority' => 'low', 'project' => $project, 'channel' => 'email', 'fields' => ['size' => 12], 'due' => CarbonImmutable::now()->addDays(3)]);
    CarbonImmutable::setTestNow('2026-10-19 12:00:00');
    $c = $make('Laptop broken', $requester, ['priority' => 'urgent', 'tags' => ['laptop', 'printer'], 'channel' => 'phone']);
    $d = $make('Monitor flickers', $other, ['due' => CarbonImmutable::now()->addDay()]);
    $e = $make('Duplicate jam', $requester);

    Tickets::for($a)->asSystem()->assign($agent);
    Tickets::for($b)->asSystem()->assign($team);
    Tickets::for($c)->by($agent)->resolve();
    Tickets::for($e)->asSystem()->mergeInto($a);
    TicketWatcher::query()->create(['ticket_id' => $d->getKey(), 'watcher_type' => $agent->getMorphClass(), 'watcher_id' => $agent->getKey()]);

    return compact('agent', 'requester', 'team', 'project', 'parent', 'child');
}

/**
 * @return list<string>
 */
function indexReferences(TestResponse $response): array
{
    return collect($response->assertOk()->json('data'))->pluck('reference')->sort()->values()->all();
}

describe('scope_and_api_filter_parity', function (): void {
    it('returns exactly the rows of the matching scope', function (string $query, Closure $scope): void {
        $world = indexWorld();

        $api = indexReferences($this->actingAs($world['agent'])->getJson('/api/tickets?per_page=100&'.$query));
        $php = Ticket::query()->tap(static fn (Builder $tickets) => $scope($tickets, $world))->pluck('reference')->sort()->values()->all();

        expect($api)->toBe($php)->and($api)->not->toBe([]);
    })->with([
        'status' => ['filter[status]=new,resolved', fn (Builder $q) => $q->withStatus('new', 'resolved')],
        'status_category' => ['filter[status_category]=resolved,closed', fn (Builder $q) => $q->withStatusCategory(StatusCategory::Resolved, StatusCategory::Closed)],
        'priority' => ['filter[priority]=high,urgent', fn (Builder $q) => $q->withPriority('high', 'urgent')],
        'category' => ['filter[category]=1', fn (Builder $q) => $q->inCategory(1)],
        'category with descendants' => ['filter[category]=1&filter[category_descendants]=1', fn (Builder $q) => $q->inCategory(1, descendants: true)],
        'assignee' => ['filter[assignee]=team:1,user:1', fn (Builder $q, $w) => $q->assignedTo($w['team'], $w['agent'])],
        'assignee me' => ['filter[assignee]=me', fn (Builder $q, $w) => $q->assignedTo($w['agent'])],
        'unassigned' => ['filter[assignee]=none', fn (Builder $q) => $q->unassigned()],
        'requester' => ['filter[requester]=user:2', fn (Builder $q, $w) => $q->requestedBy($w['requester'])],
        'project' => ['filter[project]=project:1', fn (Builder $q, $w) => $q->inProject($w['project'])],
        'no project' => ['filter[project]=none', fn (Builder $q) => $q->inProject(null)],
        'tag' => ['filter[tag]=printer', fn (Builder $q) => $q->taggedWith('printer')],
        'watching' => ['filter[watching]=me', fn (Builder $q, $w) => $q->watchedBy($w['agent'])],
        'overdue' => ['filter[overdue]=1', fn (Builder $q) => $q->overdue()],
        'due from' => ['filter[due_from]=2026-10-19', fn (Builder $q) => $q->dueFrom(CarbonImmutable::parse('2026-10-19'))],
        'due to' => ['filter[due_to]=2026-10-20T12:00:00Z', fn (Builder $q) => $q->dueTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'))],
        'created from' => ['filter[created_from]=2026-10-18', fn (Builder $q) => $q->createdFrom(CarbonImmutable::parse('2026-10-18'))],
        'created to' => ['filter[created_to]=2026-10-18T23:00:00Z', fn (Builder $q) => $q->createdTo(CarbonImmutable::parse('2026-10-18T23:00:00Z'))],
        'channel' => ['filter[channel]=email,phone', fn (Builder $q) => $q->viaChannel('email', 'phone')],
        'merged' => ['filter[merged]=true', fn (Builder $q) => $q->merged()],
        'not merged' => ['filter[merged]=false', fn (Builder $q) => $q->merged(false)],
        'search' => ['filter[search]=jam', fn (Builder $q) => $q->search('jam')],
        'a field' => ['filter[fields.size]=12', fn (Builder $q) => $q->whereField('size', '12')],
        'a field range' => ['filter[fields.size.from]=2&filter[fields.size.to]=5', fn (Builder $q) => $q->whereFieldBetween('size', 2, 5)],
        'a one-sided field range' => ['filter[fields.size.from]=10', fn (Builder $q) => $q->whereFieldBetween('size', 10, null)],
    ]);

    it('follows a swapped status enum', function (): void {
        config()->set('tickets.lifecycle', HostLifecycle::class);
        $agent = apiAgent();
        $working = apiTicket();
        Tickets::for($working)->asSystem()->transition('pick');
        apiTicket();

        $api = indexReferences($this->actingAs($agent)->getJson('/api/tickets?filter[status_category]=active'));

        expect($api)->toBe([$working->reference])
            ->and(Ticket::query()->withStatusCategory(StatusCategory::Active)->pluck('reference')->all())->toBe($api);
    });

    it('refuses an encrypted or unknown field in the scope and leaves it off the allow-list', function (string $key): void {
        $world = indexWorld();

        expect(fn () => Ticket::query()->whereField($key, 'x')->get())->toThrow(UnknownTicketFieldException::class);
        $this->actingAs($world['agent'])->getJson("/api/tickets?filter[fields.{$key}]=x")->assertStatus(400);
    })->with(['secret', 'nope']);

    afterEach(fn () => CarbonImmutable::setTestNow());
});

describe('the index', function (): void {
    it('matches nothing for an unknown status category, a missing record or another watcher', function (string $query): void {
        $world = indexWorld();

        expect(indexReferences($this->actingAs($world['agent'])->getJson('/api/tickets?'.$query)))->toBe([]);
    })->with([
        'filter[status_category]=nope',
        'filter[assignee]=user:999',
        'filter[watching]=someone',
    ]);

    it('refuses a malformed date and an unknown filter or sort', function (string $query, int $status): void {
        $this->actingAs(apiAgent())->getJson('/api/tickets?'.$query)->assertStatus($status);
    })->with([
        'a bad date' => ['filter[due_from]=someday', 422],
        'an unknown filter' => ['filter[nope]=1', 400],
        'an unknown sort' => ['sort=subject', 400],
        'a page too big' => ['per_page=101', 422],
    ]);

    it('sorts, and keeps last_activity_at for agents', function (): void {
        $world = indexWorld();

        $byPriority = collect($this->actingAs($world['agent'])->getJson('/api/tickets?sort=-priority')->json('data'))->pluck('priority.value')->all();
        $byNumber = collect($this->actingAs($world['agent'])->getJson('/api/tickets?sort=number')->json('data'))->pluck('number')->all();

        expect($byPriority)->toBe(['urgent', 'high', 'normal', 'normal', 'low'])
            ->and($byNumber)->toBe([1, 1, 2, 3, 4]);
        $this->actingAs($world['requester'])->getJson('/api/tickets?sort=last_activity_at')->assertStatus(400);
        $this->actingAs($world['requester'])->getJson('/api/tickets')->assertOk()->assertJsonMissingPath('data.0.last_activity_at');
    });

    it('lists deleted tickets for the manage ability only', function (): void {
        $world = indexWorld();
        $admin = apiAdmin();
        Tickets::for(Ticket::query()->firstOrFail())->asSystem()->delete();

        expect(count($this->actingAs($admin)->getJson('/api/tickets?filter[trashed]=only')->json('data')))->toBe(1);
        $this->actingAs($world['agent'])->getJson('/api/tickets?filter[trashed]=only')->assertStatus(400);
    });

    it('pages within routes.max_per_page', function (): void {
        $agent = apiAgent();

        foreach (range(1, 3) as $n) {
            apiTicket();
        }

        $page = $this->actingAs($agent)->getJson('/api/tickets?per_page=2&page=2')->assertOk();

        expect($page->json('meta.per_page'))->toBe(2)->and($page->json('meta.total'))->toBe(3)->and($page->json('data'))->toHaveCount(1);
        config()->set('tickets.routes.max_per_page', 2);
        $this->actingAs($agent)->getJson('/api/tickets?per_page=3')->assertUnprocessable();
        expect($this->actingAs($agent)->getJson('/api/tickets')->json('meta.per_page'))->toBe(2);
    });
});

describe('agent_only_filters_answer_agents_only', function (): void {
    it('refuses the tag filter to a non-agent', function (): void {
        $requester = apiUser();
        $ticket = Tickets::make()->subject('Refund please')->requester($requester)->asSystem()->tags(['angry-customer'])->create();

        expect($this->actingAs($requester)->getJson('/api/tickets/'.$ticket->reference)->assertOk()->json('data'))->not->toHaveKey('tags');
        $this->actingAs($requester)->getJson('/api/tickets?filter[tag]=angry-customer')->assertStatus(400);
        $this->actingAs($requester)->getJson('/api/tickets?filter[tag]=vip')->assertStatus(400);
    });

    it('matches a requester by alias for agents only, and `me` for everyone', function (): void {
        $requester = apiUser('Secret Sam');
        $watcher = apiUser('Wendy');
        $ticket = apiTicket($requester, 'Payroll');
        TicketWatcher::query()->create(['ticket_id' => $ticket->getKey(), 'watcher_type' => $watcher->getMorphClass(), 'watcher_id' => $watcher->getKey()]);
        $mine = apiTicket($watcher, 'Mine');

        expect(indexReferences($this->actingAs($watcher)->getJson('/api/tickets?filter[requester]=user:'.$requester->getKey())))->toBe([])
            ->and(indexReferences($this->actingAs($watcher)->getJson('/api/tickets?filter[requester]=user:999')))->toBe([])
            ->and(indexReferences($this->actingAs($watcher)->getJson('/api/tickets?filter[requester]=me')))->toBe([$mine->reference])
            ->and(indexReferences($this->actingAs($watcher)->getJson('/api/tickets?filter[requester]=user:'.$watcher->getKey())))->toBe([$mine->reference])
            ->and(indexReferences($this->actingAs(apiAgent())->getJson('/api/tickets?filter[requester]=user:'.$requester->getKey())))->toBe([$ticket->reference]);
    });

    it('narrows the agent-only filters to the tickets the agent scope lets the agent work on', function (string $query): void {
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        $sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $hr = Project::query()->create(['name' => 'People', 'key' => 'HR']);
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $boss = apiUser('Boss');
        Tickets::fields()->make('risk', FieldType::Number)->agentOnly()->define();
        $mine = Tickets::make()->subject('Payslip wrong')->requester($boss)->project($hr)->asSystem()->tags(['fraud'])->fields(['risk' => 9])->create();
        TicketWatcher::query()->create(['ticket_id' => $mine->getKey(), 'watcher_type' => $scoped->getMorphClass(), 'watcher_id' => $scoped->getKey()]);
        $inScope = Tickets::make()->subject('VPN drops')->requester($boss)->project($sup)->asSystem()->tags(['fraud'])->fields(['risk' => 9])->create();

        // The out-of-scope ticket shows them neither its tags nor the field…
        $show = $this->actingAs($scoped)->getJson('/api/tickets/'.$mine->reference)->assertOk();
        expect($show->json('data'))->not->toHaveKey('tags')
            ->and(collect($show->json('data.fields'))->pluck('key')->all())->not->toContain('risk');

        // …so no filter answers for it; the in-scope ticket still matches.
        expect(indexReferences($this->actingAs($scoped)->getJson('/api/tickets?'.$query)))->toBe([$inScope->reference]);
    })->with([
        'a tag' => ['filter[tag]=fraud'],
        'an agent-only field' => ['filter[fields.risk]=9'],
        'an agent-only field range' => ['filter[fields.risk.from]=5'],
        'a requester by alias' => ['filter[requester]=user:2'],
    ]);

    it('sorts tickets outside the agent scope by their public updated_at, never by note activity', function (): void {
        config()->set('tickets.authorization.agent_scope', ProjectAgentScope::class);
        $sup = Project::query()->create(['name' => 'Support', 'key' => 'SUP']);
        $hr = Project::query()->create(['name' => 'People', 'key' => 'HR']);
        $scoped = User::query()->create(['name' => 'Scoped', 'is_agent' => true]);
        $hrAgent = apiAgent('Hilda');

        CarbonImmutable::setTestNow('2026-10-17 09:00:00');
        $inScope = Tickets::make()->subject('VPN drops')->requester(apiUser())->project($sup)->asSystem()->create();
        CarbonImmutable::setTestNow('2026-10-17 10:00:00');
        $older = Tickets::make()->subject('Payslip wrong')->requester($scoped)->project($hr)->asSystem()->create();
        CarbonImmutable::setTestNow('2026-10-17 11:00:00');
        $newer = Tickets::make()->subject('Payslip still wrong')->requester($scoped)->project($hr)->asSystem()->create();
        CarbonImmutable::setTestNow('2026-10-17 12:00:00');
        Tickets::for($older)->by($hrAgent)->note('HR-INTERNAL: escalate to legal');
        CarbonImmutable::setTestNow('2026-10-17 13:00:00');
        Tickets::for($inScope)->by($scoped)->note('Looking into it.');

        $order = static fn (TestResponse $response): array => collect($response->assertOk()->json('data'))->pluck('reference')->all();

        expect($order($this->actingAs($scoped)->getJson('/api/tickets?sort=-last_activity_at')))->toBe([$inScope->reference, $newer->reference, $older->reference])
            ->and($order($this->actingAs($scoped)->getJson('/api/tickets')))->toBe([$inScope->reference, $newer->reference, $older->reference])
            ->and($order($this->actingAs($scoped)->getJson('/api/tickets?sort=last_activity_at')))->toBe([$older->reference, $newer->reference, $inScope->reference])
            ->and($order($this->actingAs($hrAgent)->getJson('/api/tickets?sort=-last_activity_at')))->toBe([$inScope->reference, $older->reference, $newer->reference]);
    });

    afterEach(fn () => CarbonImmutable::setTestNow());
});

describe('index_has_no_n_plus_one', function (): void {
    /**
     * Tickets with every relation the list renders: a category, an assignee, a requester (users
     * and guests), tags and messages.
     */
    function indexSeed(int $count, User $agent): void
    {
        $category = TicketCategory::factory()->create();
        $team = Team::query()->create(['name' => 'Hardware']);

        foreach (range(1, $count) as $n) {
            $requester = $n % 2 === 0
                ? Tickets::guests()->resolve(['name' => 'Guest '.$n, 'email' => "g{$n}@acme.test"])
                : User::query()->create(['name' => 'User '.$n]);
            $ticket = Tickets::make()->subject('Ticket '.$n)->requester($requester)->category($category)->tags(['t'.($n % 3)])->asSystem()->create();
            Tickets::for($ticket)->asSystem()->assign($n % 2 === 0 ? $team : $agent)->reply('Hi.');
            Tickets::for($ticket)->asSystem()->note('Psst.');
        }
    }

    it('lists 25 tickets in the same number of queries as 5', function (string $who): void {
        $agent = apiAgent();
        $viewer = $who === 'agent' ? $agent : apiUser('Watcher');
        indexSeed(5, $agent);

        $count = function () use ($viewer): int {
            if ($viewer->is_agent === false) {
                foreach (Ticket::query()->get() as $ticket) {
                    TicketWatcher::query()->firstOrCreate(['ticket_id' => $ticket->getKey(), 'watcher_type' => $viewer->getMorphClass(), 'watcher_id' => $viewer->getKey()]);
                }
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->getJson('/api/tickets?per_page=100')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $five = $count();
        indexSeed(20, $agent);
        $twentyFive = $count();

        expect(Ticket::query()->count())->toBe(25)
            ->and($twentyFive)->toBe($five)
            ->and($five)->toBeLessThan(20);
    })->with(['agent', 'watcher']);
});
