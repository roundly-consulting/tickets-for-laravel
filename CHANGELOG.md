# Changelog

All notable changes to `tickets-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- A headless helpdesk core for Laravel 12 and 13 on MySQL or PostgreSQL, built on the roundly
  lifecycle, comments, attributes, translatable, sluggable, media-library and query-builder
  packages.
- The `Tickets` facade over an injectable `TicketsManager`, with every operation a plain action:
  `Tickets::make()` (a fluent ticket builder) and `Tickets::create(CreateTicketData)`,
  `find()` / `findOrFail()` by reference (a soft-deleted ticket with `withTrashed: true`),
  `for($ticket)` (a model or a reference such as `SUP-42`) returning a chainable `TicketHandle`,
  `visibleTo($user)`, `project($project)`, the `categories()`, `fields()`, `tags()` and `guests()`
  sub-accessors and `sweep()`.
- Lookups through the model seam: `Tickets::fields()->find()` by id or key and
  `Tickets::tags()->find()` by id or slug (a project's own before the global one), and the
  read-only `Tickets::guests()->find()` by a normalised identifying value.
- Human references (`TCK-42`, or `SUP-42` with a project prefix) from gap-free, row-locked
  sequences — one global sequence plus one per project; numbers are never reused.
- Publish-only migrations (`tickets-migrations`) for tickets, nested categories, custom field
  definitions, tags, guests, watchers, ticket links, an append-only activity timeline and the
  reference sequences. Bigint, uuid and ulid keys through `tickets.primary_key_type` (tickets and
  guests) and `tickets.key_type` (requester, assignee, project, watcher and actor morphs).
- Five swappable models (`tickets.models.*`): `Ticket`, `TicketCategory`, `TicketField`,
  `TicketTag` and `TicketGuest` — every query, relation, listener and packaged factory builds the
  configured class.
- Statuses on lifecycle-for-laravel: the shipped `TicketLifecycle` (seven states in five status
  categories, nine transitions), swappable through `tickets.lifecycle`; `transition()`, the chainable
  conveniences `start()`, `wait()`, `hold()`, `resume()`, `resolve()`, `reopen()`, `close()` and
  `dismiss()` (`TicketLifecycle::*` constants), `can()`, `allowedTransitions()` and `status()`.
  A convenience a host lifecycle lacks throws `UnknownTicketTransitionException`.
- Automations a lifecycle maps through `Contracts\TicketLifecycle::automation()`: a new ticket opens
  when assigned, a requester's reply resumes a waiting ticket and reopens a resolved one
  (`tickets.comments.reply_reopens`), a merge dismisses the source. Auto-close of resolved tickets
  after `tickets.auto_close.after` (`P7D`, `off` to disable) through `lifecycle:sweep`.
- A conversation lock driven by status categories: a ticket's comments lock when it enters a Closed
  state and unlock when it leaves one, for any lifecycle.
- The conversation on comments-for-laravel: `reply()` (public, optionally with a transition applied
  atomically) and `note()` (internal, agents only), `editComment()` and `deleteComment()` (each
  left in the timeline as `comment_edited` / `comment_deleted`, as visible as the message) and
  `thread()`; author roles (requester, agent, participant, system); `first_responded_at`; host
  moderation respected (a `require_approval` hold is approved, a blocklist hold never is); mentions
  passed on only to people who may read the comment.
- Priorities through the swappable `TicketPriority` enum (`Contracts\Priority`), tolerant of stored
  values the enum no longer has; manual due dates with a swappable `Contracts\DueDateResolver`.
- Nested, translatable categories (`Tickets::categories()`, `CategoryBuilder`) with default priority,
  assignee and due offset (an ISO-8601 duration or a `CarbonInterval`) applied once at creation, cycle, depth and project rules, and deletion with
  reassignment.
- Assignment to any `Contracts\Assignee` (a user, a team): `assign()`, `unassign()`, `claim()`,
  eligibility checks and a swappable `Contracts\AssignmentStrategy`.
- Watchers with auto-watch (`tickets.watchers.auto.*`), tags (global or per project, free-form or
  curated), links (`relates_to`, `duplicates`, `blocks`, `parent_of`, with cycle checks) and merge
  (conversation, watchers, tags and attachments move; both requesters see the combined public
  thread).
- Private ticket attachments on media-library (`tickets.attachments.*`, a private disk by default).
- Custom fields: definitions per project, category or globally (`Tickets::fields()`, `FieldBuilder`),
  eleven field types, allow-listed validation rules, requester visibility and editability,
  encryption, locked types once values exist, the `whereField()` / `whereFieldBetween()` scopes,
  and pruning of orphaned values (`Tickets::fields()->prune()`, `tickets:prune-fields`).
- Guest requesters (`Tickets::guests()`): fields declared in `tickets.guests.fields`, matched by
  normalised identifying values, never routed to another person's contact, race-free, and
  converted to a registered user with every ticket and watch.
- Projects as a contract: `Contracts\TicketProject`, the `Concerns\HasTickets` and
  `Concerns\RequestsTickets` model traits, `Tickets::project()` and per-project numbering.
- Ticket query scopes: `visibleTo`, `assignedTo`, `unassigned`, `requestedBy`, `inProject`,
  `inCategory`, `withStatus`, `withStatusCategory`, `unresolved`, `withPriority`, `taggedWith`,
  `watchedBy`, `whereField`, `whereFieldBetween`, `dueBefore`, `dueAfter`, `dueFrom`, `dueTo`,
  `createdFrom`, `createdTo`, `viaChannel`, `search`, `overdue`, `merged` and `orderByPriority`.
- Model conveniences for "my tickets" pages and badges: `watchedTickets()` and `visibleTickets()` on
  `Concerns\RequestsTickets`, `requestedTickets()` and `watchedTickets()` on `TicketGuest`, and the
  `Ticket` read helpers `isUnresolved()`, `isResolved()`, `isClosed()`, `isOverdue()`, `isMerged()`
  and `isAssigned()`, each agreeing with its query scope.
- An append-only activity timeline (`tickets.activity.enabled`) and 34 after-commit events, from
  `TicketCreated` to `TicketCommentUpdated`, `TicketCommentDeleted` and `GuestAccessRevoked`; every
  ticket event implements `Contracts\TicketEvent` (`ticket()`, `actor()`), so one typed listener
  hears them all — for an audit log, webhooks or a dashboard feed.
- `Tickets::sweep()` and `php artisan tickets:sweep`: `TicketOverdue` and `TicketDueSoon`
  (`tickets.due.soon_window`) exactly once per due date, safe under concurrent sweeps, returning a
  `SweepResult`.
- An opt-in JSON API (`tickets.routes.enabled`, off by default; `tickets-routes` to publish the
  route file): tickets, transitions, assignment, replies and notes, field values, watchers, tags,
  links, merge, signed attachment URLs, the activity timeline, bulk actions and category, field and
  tag admin endpoints; role-aware resources that present statuses, priorities and channels with
  their translated label and colour (`TicketChannel` for the shipped channels); query-builder filters and sorts built on the query
  scopes; `{message, code}` errors without touching the host's exception handler; the
  `tickets-api` and `tickets-guest` rate limiters.
- `Policies\TicketPolicy` (swappable, registered with `tickets.authorization.register_policy`) on the
  `tickets.agent`, `tickets.assign` and `tickets.manage` Gate abilities — undefined abilities deny —
  and `Contracts\AgentScope` to narrow what an agent works on.
- Guest access through temporary signed links (`guestLink()`, `Contracts\GuestLinkGenerator`):
  guest endpoints that embed signed action URLs, one 403 for every unusable link, and
  `revokeGuestAccess()`.
- Notifications (on by default, `['mail']`, queued after commit, English and Slovak) for a new
  ticket, an assignment, replies, internal notes, mentions, status changes, overdue and due-soon
  tickets and merges — visibility-checked at send time and again on delivery, each type a swappable
  class or `false`.
- `php artisan tickets:install` (publishes the config and every migration an installation needs,
  shows the key types to align, names the three ways to give guest mails a link), `php artisan tickets:doctor` (`--json`; checks the whole
  installation) and an `about` section.
- `Tickets::fake()`: a recording, still-performing fake with an assertion for every operation,
  including calls through handles, sub-accessors, builders and model traits — each one documented
  on the facade, each `assert<Verb>()` paired with `assertNothing<Verb>()` and each
  `assert<Noun><Verb>()` with `assertNo<Noun><Verb>()`; `assertTransitioned()` also counts a
  published reply's explicit `transition:`.
- English and Slovak translations for every enum label, message and notification.
