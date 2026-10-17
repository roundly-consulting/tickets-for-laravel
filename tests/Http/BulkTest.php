<?php

declare(strict_types=1);

use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(fn () => apiAbilities());

describe('bulk_*', function (): void {
    it('runs one action on each ticket and reports each result', function (array $payload, Closure $check): void {
        $agent = apiAdmin();
        Team::query()->create(['name' => 'Hardware']);
        $tickets = [apiTicket(), apiTicket(), apiTicket()];
        $references = array_map(static fn (Ticket $ticket): string => $ticket->reference, $tickets);

        $response = $this->actingAs($agent)->postJson('/api/tickets/bulk', [...$payload, 'tickets' => $references])->assertOk();

        expect(collect($response->json('data'))->pluck('ok')->all())->toBe([true, true, true], (string) $response->getContent());
        foreach ($tickets as $ticket) {
            $check($ticket->fresh() ?? $ticket, $agent);
        }
    })->with([
        'assign' => [['action' => 'assign', 'assignee' => ['type' => 'team', 'id' => 1]], fn (Ticket $t) => expect($t->assignee_type)->toBe((new Team)->getMorphClass())],
        'claim' => [['action' => 'assign', 'claim' => true], fn (Ticket $t, User $agent) => expect($t->assignee_id)->toBe($agent->id)],
        'unassign' => [['action' => 'unassign'], fn (Ticket $t) => expect($t->assignee_id)->toBeNull()],
        'transition' => [['action' => 'transition', 'transition' => 'hold', 'reason' => 'Vendor'], fn (Ticket $t) => expect($t->status?->value)->toBe('on_hold')],
        'priority' => [['action' => 'priority', 'priority' => 'urgent'], fn (Ticket $t) => expect($t->priority)->toBe('urgent')],
        'tags' => [['action' => 'tags', 'tags' => ['attach' => ['printer', 'vip'], 'detach' => ['vip']]], fn (Ticket $t) => expect($t->tags()->pluck('slug')->all())->toBe(['printer'])],
    ]);

    it('checks the policy per ticket and reports partial failures', function (): void {
        $agent = apiAgent();
        $requester = apiUser();
        $mine = apiTicket($requester);
        $closed = apiTicket();
        Tickets::for($closed)->asSystem()->dismiss('Spam');

        $agentRun = $this->actingAs($agent)->postJson('/api/tickets/bulk', [
            'action' => 'transition', 'transition' => 'start', 'tickets' => [$mine->reference, $closed->reference, 'TCK-404'],
        ])->assertOk()->json('data');
        $requesterRun = $this->actingAs($requester)->postJson('/api/tickets/bulk', [
            'action' => 'priority', 'priority' => 'urgent', 'tickets' => [$mine->reference, $closed->reference],
        ])->assertOk()->json('data');

        expect($agentRun)->toBe([
            ['ticket' => 'TCK-1', 'ok' => true, 'status' => 200],
            ['ticket' => 'TCK-2', 'ok' => false, 'status' => 422, 'code' => 'ticket_transition_denied', 'message' => $agentRun[1]['message']],
            ['ticket' => 'TCK-404', 'ok' => false, 'status' => 404, 'code' => 'ticket_not_found', 'message' => 'Ticket [TCK-404] was not found.'],
        ])->and($requesterRun[0])->toMatchArray(['ok' => false, 'status' => 403, 'code' => 'forbidden'])
            ->and($requesterRun[1])->toMatchArray(['ok' => false, 'status' => 404, 'code' => 'ticket_not_found'])
            ->and($mine->fresh()?->status?->value)->toBe('in_progress')
            ->and($mine->fresh()?->priority)->toBe('normal');
    });

    it('reports an invalid value and a refused assignment per ticket', function (): void {
        $junior = User::query()->create(['name' => 'Junior', 'is_agent' => true]);
        $ticket = apiTicket();

        $priority = $this->actingAs(apiAdmin())->postJson('/api/tickets/bulk', ['action' => 'priority', 'priority' => 'meh', 'tickets' => [$ticket->reference]])->json('data.0');
        $assign = $this->actingAs($junior)->postJson('/api/tickets/bulk', ['action' => 'assign', 'assignee' => ['type' => 'user', 'id' => apiAgent()->id], 'tickets' => [$ticket->reference]])->json('data.0');

        expect($priority)->toMatchArray(['ok' => false, 'status' => 422, 'code' => 'validation_failed'])
            ->and($assign)->toMatchArray(['ok' => false, 'status' => 403]);
    });

    it('refuses more tickets than routes.bulk.max, and an incomplete action', function (array $payload, string $error): void {
        config()->set('tickets.routes.bulk.max', 2);

        $this->actingAs(apiAgent())->postJson('/api/tickets/bulk', $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'too many' => [['action' => 'unassign', 'tickets' => ['TCK-1', 'TCK-2', 'TCK-3']], 'tickets'],
        'none' => [['action' => 'unassign', 'tickets' => []], 'tickets'],
        'an unknown action' => [['action' => 'delete', 'tickets' => ['TCK-1']], 'action'],
        'a transition without its name' => [['action' => 'transition', 'tickets' => ['TCK-1']], 'transition'],
        'an assignment without an assignee' => [['action' => 'assign', 'tickets' => ['TCK-1']], 'assignee'],
        'a repeated ticket' => [['action' => 'unassign', 'tickets' => ['TCK-1', 'TCK-1']], 'tickets.0'],
    ]);
});
