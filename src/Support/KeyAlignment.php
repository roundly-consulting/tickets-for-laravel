<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Closure;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The key-type settings that must agree across the packages tickets builds on. Tickets'
 * inbound key (`primary_key_type`: the ticket and the guest, which lifecycle, attributes and
 * comments point at) must equal `lifecycle.key_type`, `attributes.key_type` and
 * `comments.key_type`; its outbound key (`key_type`: requesters, assignees, actors) must equal
 * `lifecycle.actor_key_type` and — because one comments setting keys both the comment's author
 * and the ticket it is on — `comments.key_type` too. So one installation uses one key type.
 *
 * @internal
 */
final class KeyAlignment
{
    /**
     * The settings that must be equal, each pair as [tickets setting, other setting].
     *
     * @var list<array{string, string}>
     */
    private const array PAIRS = [
        ['tickets.primary_key_type', 'lifecycle.key_type'],
        ['tickets.primary_key_type', 'attributes.key_type'],
        ['tickets.primary_key_type', 'comments.key_type'],
        ['tickets.key_type', 'lifecycle.actor_key_type'],
        ['tickets.key_type', 'comments.key_type'],
    ];

    /**
     * Every setting with its key type, or null when the value is no key type.
     *
     * @return array<string, KeyType|null>
     */
    public static function settings(): array
    {
        return [
            'tickets.primary_key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('tickets.primary_key_type')),
            'tickets.key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('tickets.key_type')),
            'lifecycle.key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('lifecycle.key_type')),
            'lifecycle.actor_key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('lifecycle.actor_key_type')),
            'comments.key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('comments.key_type')),
            'attributes.key_type' => self::read(static fn (): KeyType => KeyType::fromConfig('attributes.key_type')),
        ];
    }

    /**
     * What disagrees, as readable problems; empty when every setting lines up.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $settings = self::settings();
        $problems = [];

        foreach ($settings as $key => $type) {
            if ($type === null) {
                $problems[] = Messages::get('doctor.key_invalid', ['key' => $key]);
            }
        }

        foreach (self::PAIRS as [$ours, $theirs]) {
            $a = $settings[$ours];
            $b = $settings[$theirs];

            if ($a !== null && $b !== null && $a !== $b) {
                $problems[] = Messages::get('doctor.key_mismatch', ['ours' => $ours, 'type' => $a->value, 'theirs' => $theirs, 'other' => $b->value]);
            }
        }

        return $problems;
    }

    /**
     * @param  Closure(): KeyType  $read
     */
    private static function read(Closure $read): ?KeyType
    {
        try {
            return $read();
        } catch (InvalidConfigurationException) {
            return null;
        }
    }
}
