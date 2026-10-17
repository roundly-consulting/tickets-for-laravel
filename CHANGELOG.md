# Changelog

All notable changes to `tickets-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- A headless ticketing core on Laravel 12 and 13, built on the roundly lifecycle, comments,
  attributes, translatable, sluggable, media-library and query-builder packages.
- Publish-only migrations (`tickets-migrations`) for tickets, nested categories, custom field
  definitions, tags, guests, watchers, ticket links, an append-only activity timeline and
  reference sequences. Bigint, uuid and ulid keys through `tickets.primary_key_type` (the
  ticket and guest ids) and `tickets.key_type` (requester, assignee, project and actor morphs).
- Five swappable models (`tickets.models.*`): `Ticket`, `TicketCategory`, `TicketField`,
  `TicketTag` and `TicketGuest`.
- `TicketLifecycle`, the shipped status lifecycle (seven states in five status categories,
  nine transitions), swappable through `tickets.lifecycle`, and the `TicketPriority` enum,
  swappable through `tickets.priority.enum`.
- English and Slovak labels and colours for every enum, and a `php artisan about` section.
