<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Enums\TicketStatus;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Notifications\TicketAssignedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketCreatedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketDueSoonNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMentionedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketMergedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNoteAddedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketNotification;
use RoundlyConsulting\Tickets\Notifications\TicketOverdueNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketRequesterRepliedNotification;
use RoundlyConsulting\Tickets\Notifications\TicketStatusChangedNotification;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

/**
 * English and Slovak ship the identical key set and placeholders, and Slovak really
 * resolves through the package namespace.
 */
$files = array_map(
    static fn (string $path): string => basename($path, '.php'),
    glob(__DIR__.'/../../resources/lang/en/*.php') ?: [],
);

it('ships at least one translation file', function () use ($files): void {
    expect($files)->not->toBeEmpty();
});

it('ships the same keys in english and slovak', function (string $file): void {
    /** @var array<string, mixed> $en */
    $en = require __DIR__."/../../resources/lang/en/{$file}.php";
    /** @var array<string, mixed> $sk */
    $sk = require __DIR__."/../../resources/lang/sk/{$file}.php";

    expect(array_keys(Arr::dot($sk)))->toEqualCanonicalizing(array_keys(Arr::dot($en)));
})->with($files);

it('ships the same placeholders per key in english and slovak', function (string $file): void {
    /** @var array<string, string> $en */
    $en = Arr::dot(require __DIR__."/../../resources/lang/en/{$file}.php");
    /** @var array<string, string> $sk */
    $sk = Arr::dot(require __DIR__."/../../resources/lang/sk/{$file}.php");

    $placeholders = static function (string $line): array {
        preg_match_all('/:([a-z_]+)/', $line, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    };

    foreach ($en as $key => $line) {
        expect($placeholders((string) ($sk[$key] ?? '')))->toBe($placeholders((string) $line), "placeholders of {$file}.{$key}");
    }
})->with($files);

it('resolves slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('tickets::enums.ticket_status.resolved'))->toBe('Vyriešený')
        ->and(TicketStatus::Pending->label())->toBe('Čaká na žiadateľa');
});

it('renders every notification in english and in slovak', function (): void {
    Notification::fake();
    defineAgents();
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@acme.test']);
    $grace = User::query()->create(['name' => 'Grace', 'email' => 'grace@acme.test', 'is_agent' => true]);
    $ticket = Tickets::make()->subject('Printer jammed')->requester($ada)->asSystem()->create();
    $target = Tickets::make()->subject('Printer jammed again')->requester($ada)->asSystem()->create();
    $reply = Tickets::for($ticket)->by($grace)->reply('We ordered a new drum.');
    $note = Tickets::for($ticket)->by($grace)->note('The vendor is slow.');
    $due = CarbonImmutable::parse('2030-01-01 12:00:00');

    /** @var list<TicketNotification> $notifications */
    $notifications = [
        new TicketCreatedNotification($ticket),
        new TicketAssignedNotification($ticket, $grace),
        new TicketRepliedNotification($ticket, $reply),
        new TicketRequesterRepliedNotification($ticket, $reply),
        new TicketNoteAddedNotification($ticket, $note),
        new TicketMentionedNotification($ticket, $note),
        new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::RESOLVED),
        new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::CLOSED),
        new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::REOPENED),
        new TicketOverdueNotification($ticket, $due),
        new TicketDueSoonNotification($ticket, $due),
        new TicketMergedNotification($ticket, $target),
    ];

    expect(array_values(array_unique(array_map(static fn (TicketNotification $notification): string => $notification->type()->value, $notifications))))
        ->toBe(array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases()));

    $render = static function (TicketNotification $notification, string $locale) use ($ada): string {
        app()->setLocale($locale);
        $mail = $notification->toMail($ada);

        return implode("\n", [(string) $mail->subject, (string) $mail->greeting, ...$mail->introLines, (string) $mail->actionText]);
    };

    foreach ($notifications as $notification) {
        $en = $render($notification, 'en');
        $sk = $render($notification, 'sk');

        foreach ([$en, $sk] as $text) {
            expect($text)->not->toContain('tickets::')
                ->not->toMatch('/(?<![\w])\:[a-z_]+/')
                ->toContain('TCK-1');
        }

        expect($sk)->not->toBe($en)
            ->and($sk)->toContain('Dobrý deň, Ada,');
    }

    app()->setLocale('sk');

    expect((new TicketStatusChangedNotification($ticket, TicketStatusChangedNotification::RESOLVED))->toMail($ada)->subject)->toBe('[TCK-1] Vyriešené: Printer jammed')
        ->and((new TicketOverdueNotification($ticket, $due))->toMail($ada)->introLines[0])->toContain('2030')->toContain('januára');
});
