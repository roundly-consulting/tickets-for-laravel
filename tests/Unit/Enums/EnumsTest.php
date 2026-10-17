<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Attributes\Enums\AttributeType;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Enums\TicketChannel;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Enums\TicketPriority;
use RoundlyConsulting\Tickets\Enums\TicketStatus;

$enums = [
    TicketStatus::class, StatusCategory::class, Automation::class, TicketPriority::class, FieldType::class,
    LinkType::class, ActivityType::class, ActivityVisibility::class, AuthorRole::class, TicketChannel::class,
    TicketOperation::class, NotificationType::class,
];

it('gives every case a colour and a translated label in both languages', function (string $enum): void {
    foreach (['en', 'sk'] as $locale) {
        app()->setLocale($locale);

        foreach ($enum::cases() as $case) {
            expect($case)->toBeInstanceOf(HasColor::class)
                ->and($case->color())->not->toBeEmpty()
                ->and(trans()->has('tickets::enums.'.Str::snake(class_basename($enum)).'.'.$case->value, $locale, false))->toBeTrue("{$enum}::{$case->name} has no {$locale} label");
        }
    }
})->with($enums);

it('names the packaged class of every notification type, and which reach the requester side', function (): void {
    foreach (NotificationType::cases() as $type) {
        expect((new ReflectionClass($type->defaultClass()))->newInstanceWithoutConstructor()->type())->toBe($type);
    }

    expect(array_values(array_map(
        static fn (NotificationType $type): string => $type->value,
        array_filter(NotificationType::cases(), static fn (NotificationType $type): bool => $type->reachesRequesters()),
    )))->toBe(['created', 'replied', 'status_changed', 'merged']);
});

it('maps every shipped status to its category', function (): void {
    expect(array_map(static fn (TicketStatus $status): string => $status->category()->value, TicketStatus::cases()))
        ->toBe(['open', 'open', 'active', 'waiting', 'waiting', 'resolved', 'closed']);
});

it('knows which categories are unresolved', function (): void {
    expect(StatusCategory::unresolved())->toBe([StatusCategory::Open, StatusCategory::Active, StatusCategory::Waiting])
        ->and(StatusCategory::Waiting->isUnresolved())->toBeTrue()
        ->and(StatusCategory::Resolved->isUnresolved())->toBeFalse()
        ->and(StatusCategory::Closed->isUnresolved())->toBeFalse();
});

it('weighs priorities in ascending order', function (): void {
    expect(array_map(static fn (TicketPriority $priority): int => $priority->weight(), TicketPriority::cases()))
        ->toBe([10, 20, 30, 40]);
});

it('stores every field type as its attribute type', function (): void {
    expect(FieldType::Text->attributeType())->toBe(AttributeType::String_)
        ->and(FieldType::Textarea->attributeType())->toBe(AttributeType::String_)
        ->and(FieldType::Email->attributeType())->toBe(AttributeType::String_)
        ->and(FieldType::Url->attributeType())->toBe(AttributeType::String_)
        ->and(FieldType::Select->attributeType())->toBe(AttributeType::String_)
        ->and(FieldType::Number->attributeType())->toBe(AttributeType::Integer)
        ->and(FieldType::Decimal->attributeType())->toBe(AttributeType::Float_)
        ->and(FieldType::Boolean->attributeType())->toBe(AttributeType::Boolean)
        ->and(FieldType::Date->attributeType())->toBe(AttributeType::DateTime)
        ->and(FieldType::DateTime->attributeType())->toBe(AttributeType::DateTime)
        ->and(FieldType::Multiselect->attributeType())->toBe(AttributeType::Array_);
});

it('knows which field types carry options', function (): void {
    expect(array_values(array_filter(FieldType::cases(), static fn (FieldType $type): bool => $type->hasOptions())))
        ->toBe([FieldType::Select, FieldType::Multiselect]);
});

it('reads links from the other end with their inverse label', function (): void {
    app()->setLocale('sk');

    expect(LinkType::Blocks->inverse())->toBe('blocked_by')
        ->and(LinkType::Duplicates->inverse())->toBe('duplicated_by')
        ->and(LinkType::ParentOf->inverse())->toBe('child_of')
        ->and(LinkType::RelatesTo->inverse())->toBe('relates_to')
        ->and(LinkType::Blocks->inverseLabel())->toBe('Blokovaný tiketom')
        ->and(LinkType::RelatesTo->isSymmetric())->toBeTrue()
        ->and(LinkType::Blocks->isSymmetric())->toBeFalse()
        ->and(LinkType::Blocks->isAcyclic())->toBeTrue()
        ->and(LinkType::ParentOf->isAcyclic())->toBeTrue()
        ->and(LinkType::Duplicates->isAcyclic())->toBeFalse();
});

it('falls back to the inverse key when no label is translated', function (): void {
    app('translator')->addLines(['enums.link_inverse.child_of' => ''], 'en', 'tickets');
    app('translator')->setLoaded([]);

    expect(LinkType::ParentOf->inverseLabel())->toBeString();
});

it('keeps notes and agent-side changes out of the public timeline', function (): void {
    expect(ActivityType::NoteAdded->defaultVisibility())->toBe(ActivityVisibility::Internal)
        ->and(ActivityType::WatcherAdded->defaultVisibility())->toBe(ActivityVisibility::Internal)
        ->and(ActivityType::GuestAccessRevoked->defaultVisibility())->toBe(ActivityVisibility::Internal)
        ->and(ActivityType::Replied->defaultVisibility())->toBe(ActivityVisibility::Public)
        ->and(ActivityType::StatusChanged->defaultVisibility())->toBe(ActivityVisibility::Public)
        ->and(ActivityType::Created->defaultVisibility())->toBe(ActivityVisibility::Public);
});

it('names the categories each automation leaves and enters', function (): void {
    expect(Automation::Assigned->sources())->toBe([StatusCategory::Open])
        ->and(Automation::RequesterReplied->sources())->toBe([StatusCategory::Waiting])
        ->and(Automation::RequesterReopened->sources())->toBe([StatusCategory::Resolved])
        ->and(Automation::AutoClose->sources())->toBe([StatusCategory::Resolved])
        ->and(Automation::Merged->sources())->toBe([StatusCategory::Open, StatusCategory::Active, StatusCategory::Waiting, StatusCategory::Resolved])
        ->and(Automation::Assigned->targets())->toBe([StatusCategory::Open, StatusCategory::Active])
        ->and(Automation::RequesterReopened->targets())->toBe([StatusCategory::Open, StatusCategory::Active, StatusCategory::Waiting])
        ->and(Automation::Merged->targets())->toBe([StatusCategory::Closed]);
});
