<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketAttachmentAdded;
use RoundlyConsulting\Tickets\Events\TicketAttachmentRemoved;
use RoundlyConsulting\Tickets\Exceptions\TicketAttachmentNotFoundException;
use RoundlyConsulting\Tickets\Facades\Tickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

function attachTicket(): Ticket
{
    return Tickets::make()->subject('Scanner jams')->requester(User::query()->create(['name' => 'Ada']))->asSystem()->create();
}

describe('attachments_are_private', function (): void {
    it('stores ticket attachments on the configured private disk, never the public default', function (): void {
        $ticket = attachTicket();

        $media = Tickets::for($ticket)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('scan.txt', 'Error 42'));

        expect(config('media.disk'))->toBe('local')
            ->and($media->disk)->toBe('local')
            ->and($media->isPrivate())->toBeTrue()
            ->and(Storage::disk('local')->exists($media->getPath()))->toBeTrue()
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('keeps them off the public disk even when media-library defaults to it', function (): void {
        config()->set('media.disk', 'public');
        $ticket = attachTicket();

        $media = Tickets::for($ticket)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('scan.txt', 'Error 42'));

        expect($media->disk)->toBe('local')
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('stores on the disk the host names', function (): void {
        config()->set('filesystems.disks.vault', ['driver' => 'local', 'root' => storage_path('app/vault')]);
        Storage::fake('vault');
        config()->set('tickets.attachments.disk', 'vault');

        $media = Tickets::for(attachTicket())->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('scan.txt', 'Error 42'));

        expect($media->disk)->toBe('vault')
            ->and(Storage::disk('vault')->exists($media->getPath()))->toBeTrue();
    });

    it('warns in the doctor about a web-served attachments disk', function (): void {
        $read = static function (): array {
            Artisan::call('tickets:doctor', ['--json' => true]);

            /** @var array{checks: list<array{section: string, code: string, level: string}>} $output */
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            return array_values(array_filter($output['checks'], static fn (array $check): bool => $check['section'] === 'attachments'));
        };

        expect(array_column($read(), 'level'))->toBe(['ok']);

        config()->set('tickets.attachments.disk', 'public');

        expect(array_column($read(), 'level'))->toBe(['warning']);
    });
});

describe('ticket attachments', function (): void {
    it('adds, lists and removes attachments with an activity entry and an event each', function (): void {
        Event::fake([TicketAttachmentAdded::class, TicketAttachmentRemoved::class]);
        $ticket = attachTicket();
        $attachments = Tickets::for($ticket)->asSystem()->attachments();

        $first = $attachments->add(UploadedFile::fake()->createWithContent('one.txt', 'One'));
        $second = $attachments->add(UploadedFile::fake()->createWithContent('two.txt', 'Two'));

        expect($attachments->all()->pluck('uuid')->all())->toBe([$first->uuid, $second->uuid])
            ->and($attachments->remove($first))->toBe($attachments)
            ->and($attachments->remove($second->uuid)->all())->toHaveCount(0)
            ->and($attachments->ticket())->toBe($ticket)
            ->and(keySorted($ticket->activities()->where('type', ActivityType::AttachmentAdded->value)->first()?->properties))->toBe(['media' => $first->uuid, 'name' => 'one.txt'])
            ->and($ticket->activities()->where('type', ActivityType::AttachmentRemoved->value)->count())->toBe(2);

        Event::assertDispatchedTimes(TicketAttachmentAdded::class, 2);
        Event::assertDispatchedTimes(TicketAttachmentRemoved::class, 2);
    });

    it('refuses to remove another ticket\'s attachment, or another bucket\'s media', function (): void {
        $mine = attachTicket();
        $theirs = attachTicket();
        $foreign = Tickets::for($theirs)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('a.txt', 'Alpha, a plain text line.'));
        $other = MediaLibrary::for($mine)->add(UploadedFile::fake()->createWithContent('b.txt', 'Bravo, a plain text line.'))->toBucket('default');

        expect(fn () => Tickets::for($mine)->asSystem()->attachments()->remove($foreign))->toThrow(TicketAttachmentNotFoundException::class, 'TCK-1')
            ->and(fn () => Tickets::for($mine)->asSystem()->attachments()->remove($other))->toThrow(TicketAttachmentNotFoundException::class)
            ->and(fn () => Tickets::for($mine)->asSystem()->attachments()->remove('no-such-uuid'))->toThrow(TicketAttachmentNotFoundException::class)
            ->and(Tickets::for($theirs)->attachments()->all())->toHaveCount(1);
    });

    it('refuses a file type or size the bucket does not take', function (): void {
        $ticket = attachTicket();
        config()->set('tickets.attachments.max_kb', 1);

        expect(fn () => Tickets::for($ticket)->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('run.sh', "#!/bin/sh\necho hi\n")))
            ->toThrow(FileUnacceptableForBucket::class)
            ->and(fn () => Tickets::for(attachTicket())->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('big.txt', str_repeat('x', 4096))))
            ->toThrow(FileUnacceptableForBucket::class)
            ->and(Tickets::for($ticket)->attachments()->all())->toHaveCount(0);
    });

    it('takes any type with an empty mime list', function (): void {
        config()->set('tickets.attachments.mimes', []);

        $media = Tickets::for(attachTicket())->asSystem()->attachments()->add(UploadedFile::fake()->createWithContent('run.sh', "#!/bin/sh\necho hi\n"));

        expect($media->exists)->toBeTrue();
    });

    it('files a ticket with its attachments', function (): void {
        $ticket = Tickets::make()->subject('a')->requester(User::query()->create())
            ->attach(UploadedFile::fake()->createWithContent('one.txt', 'One'), UploadedFile::fake()->createWithContent('two.txt', 'Two'))
            ->asSystem()->create();

        expect(Tickets::for($ticket)->attachments()->all()->pluck('file_name')->all())->toBe(['one.txt', 'two.txt']);
    });
});
