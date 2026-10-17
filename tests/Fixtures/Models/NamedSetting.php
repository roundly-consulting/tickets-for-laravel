<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model keyed by a natural string — neither an integer nor a unique id.
 *
 * @property string $name
 */
final class NamedSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $guarded = [];
}
