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
- The `Tickets` facade over an injectable `TicketsManager`, with every operation a plain action:
  `Tickets::make()` (a fluent ticket builder) and `Tickets::create(CreateTicketData)` file a
  ticket; `Tickets::find()` / `findOrFail()` look one up by reference; `Tickets::for($ticket)`
  (a model or a reference such as `SUP-42`) returns a chainable handle with `update()`,
  `prioritize()`, `categorize()`, `due()`, `changeRequester()`, `delete()`, `restore()`,
  `activity()`, `by($actor)` and `asSystem()`.
- Human references (`TCK-42`, or `SUP-42` with a project prefix) from gap-free, row-locked
  sequences — one global sequence plus one per project; numbers are never reused.
- Category defaults applied once at creation (caller, then the nearest category, then
  config) through the swappable `Contracts\AssignmentStrategy` and `Contracts\DueDateResolver`.
- Nested, translatable categories through `Tickets::categories()` (`make()` builder, `create`,
  `update`, `move`, `delete` with reassignment, `restore`, `find`, `tree`), with cycle, depth
  and project rules serialised on an advisory lock.
- Custom field definitions through `Tickets::fields()` (`make()` builder, `define`), with
  allow-listed validation rules and keys unique across a project's whole field set.
- Guest requesters through `Tickets::guests()` (`resolve`, `update`, `convert`): fields declared
  in `tickets.guests.fields`, matched by normalised identifying values in config order, never
  routed to another person's contact on a conflict, race-free on concurrent first tickets, and
  converted to a registered user with every ticket and watch.
- Ticket query scopes: `assignedTo`, `unassigned`, `requestedBy`, `inProject`, `inCategory`,
  `withStatus`, `withStatusCategory`, `unresolved`, `withPriority`, `taggedWith`, `watchedBy`,
  `dueBefore`, `dueAfter`, `overdue`, `merged` and `orderByPriority`.
- An append-only activity timeline and after-commit events (`TicketCreated`, `TicketUpdated`,
  `TicketAssigned`, `TicketPriorityChanged`, `TicketCategoryChanged`, `TicketDueDateChanged`,
  `TicketRequesterChanged`, `TicketDeleted`, `TicketRestored`, `GuestConverted`).
- `Tickets::fake()`: a recording, still-performing fake with an assertion per operation.
- Status changes through lifecycle-for-laravel only: `Tickets::for($ticket)->transition($name,
  reason:, payload:)` and the chainable conveniences `start()`, `wait()`, `hold()`, `resume()`,
  `resolve()`, `reopen()`, `close()` and `dismiss()` (also `TicketLifecycle::*` constants), plus
  `can()`, `allowedTransitions()` and `status()`. A host lifecycle without a convenience's
  transition throws `UnknownTicketTransitionException`.
- Automations a lifecycle maps through `Contracts\TicketLifecycle::automation()`, run as the
  system and credited to the triggering actor: a ticket created with an assignee opens.
- Auto-close of resolved tickets after `tickets.auto_close.after` (ISO-8601, `P7D` by default,
  `off` to disable) through `lifecycle:sweep`.
- A conversation lock driven by status categories: a ticket's comments lock when it enters a
  Closed state and unlock when it leaves one, for any lifecycle — rollbacks and adoptions too.
- `TicketStatusChanged`, `TicketResolved`, `TicketClosed` and `TicketReopened` events and a
  `status_changed` activity written atomically with every state change.
- `php artisan tickets:doctor` (`--json`): checks the configured lifecycle against the ticket
  contract and its automations, and lists the transition conveniences it supports.
- The ticket conversation on comments-for-laravel 1.1: `Tickets::for($ticket)->reply()` (a public
  reply, optionally with a transition applied atomically) and `note()` (an internal note, agents
  only, `visible = false` and marked internal), `editComment()`, `deleteComment()` and `thread()`
  (public, or every message with `internal: true`), plus `$ticket->reply()` / `$ticket->note()`.
  Every message carries `meta.tickets = {internal, role, channel}`; host meta keys are kept.
- Author roles computed when a message is posted (requester, agent through the
  `tickets.authorization.abilities.agent` Gate ability, watcher as participant, system); anyone
  else is refused with `TicketAccessDeniedException`, and a Closed ticket takes no message
  (`TicketClosedException`).
- Reply automations: a requester or participant reply resumes a waiting ticket and reopens a
  resolved one (`tickets.comments.reply_reopens`); the first public agent reply stamps
  `first_responded_at` once.
- Host moderation respected: a reply `comments.require_approval` held is approved
  (`tickets.comments.auto_approve`), a reply the host's blocklist held never is.
- `TicketReplied`, `TicketNoteAdded`, `TicketFirstResponded` and `TicketMentioned` (a mention
  passed on only to someone who may read the comment) events; reply and note attachments on the
  comment's private bucket; `tickets:doctor` checks the comments `meta` column and warns about
  `comments.authorization`.
