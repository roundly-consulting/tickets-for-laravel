<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\Agents;

/**
 * Who is reading an API response — a signed-in user or a guest on a signed link — and so what the
 * resources may show. The agent ability is asked once per request; whether that agent works on
 * a given ticket (the agent scope) is asked per ticket. Everyone who is not an agent on the
 * ticket gets the public view.
 *
 * @internal
 */
final readonly class Viewer
{
    private function __construct(
        public Model $model,
        private bool $agentAbility,
        public bool $guest,
    ) {}

    public static function user(Model $user): self
    {
        return new self($user, Agents::is($user), false);
    }

    public static function guest(TicketGuest $guest): self
    {
        return new self($guest, false, true);
    }

    /**
     * The viewer a controller or the guest middleware put on the request.
     */
    public static function of(Request $request): self
    {
        $viewer = $request->attributes->get(self::class);

        if ($viewer instanceof self) {
            return $viewer;
        }

        $user = $request->user();

        return self::user($user instanceof Model ? $user : throw new AuthenticationException);
    }

    public function attach(Request $request): self
    {
        $request->attributes->set(self::class, $this);

        return $this;
    }

    /**
     * Whether the viewer holds the agent ability at all — the index sorts and filters by it.
     */
    public function isAgent(): bool
    {
        return $this->agentAbility;
    }

    /**
     * Whether the viewer works on this ticket as an agent: the agent ability, and the agent scope
     * lets them.
     */
    public function isAgentOn(Ticket $ticket): bool
    {
        return $this->agentAbility && Container::getInstance()->make(AgentScope::class)->allows($this->model, $ticket);
    }

    public function is(?Model $model): bool
    {
        return $model !== null
            && $model->getMorphClass() === $this->model->getMorphClass()
            && (string) $model->getKey() === (string) $this->model->getKey();
    }
}
