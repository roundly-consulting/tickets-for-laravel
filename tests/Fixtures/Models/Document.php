<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Tickets\Tests\Fixtures\PlainLifecycle;

/**
 * Another lifecycle subject of the host — the tickets listeners must leave it alone.
 *
 * @property int $id
 * @property string|null $status
 */
final class Document extends Model implements LifecycleSubject
{
    use HasLifecycle;

    protected $table = 'documents';

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => PlainLifecycle::class];
    }
}
