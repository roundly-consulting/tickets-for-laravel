<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tickets\Http\Controllers\ActivityController;
use RoundlyConsulting\Tickets\Http\Controllers\AssignmentController;
use RoundlyConsulting\Tickets\Http\Controllers\AttachmentController;
use RoundlyConsulting\Tickets\Http\Controllers\BulkController;
use RoundlyConsulting\Tickets\Http\Controllers\CategoryController;
use RoundlyConsulting\Tickets\Http\Controllers\CommentController;
use RoundlyConsulting\Tickets\Http\Controllers\FieldController;
use RoundlyConsulting\Tickets\Http\Controllers\FieldValueController;
use RoundlyConsulting\Tickets\Http\Controllers\GuestTicketController;
use RoundlyConsulting\Tickets\Http\Controllers\LinkController;
use RoundlyConsulting\Tickets\Http\Controllers\MergeController;
use RoundlyConsulting\Tickets\Http\Controllers\TagAdminController;
use RoundlyConsulting\Tickets\Http\Controllers\TagController;
use RoundlyConsulting\Tickets\Http\Controllers\TicketController;
use RoundlyConsulting\Tickets\Http\Controllers\TransitionController;
use RoundlyConsulting\Tickets\Http\Controllers\WatcherController;
use RoundlyConsulting\Tickets\Http\Middleware\EnsureGuestTicketAccess;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/*
|--------------------------------------------------------------------------
| tickets-for-laravel — the JSON API
|--------------------------------------------------------------------------
|
| Loaded only with `tickets.routes.enabled` on. Publish this file
| (`--tag=tickets-routes`) to change it: the published copy then replaces it.
|
*/

$reference = '[A-Z][A-Z0-9]{0,9}-[0-9]+';
$key = '[0-9]+';
$uuid = '[0-9a-fA-F-]{36}';
$transition = '[a-z][a-z0-9_]{0,63}';

Route::prefix(TicketsConfig::routePrefix())->name('tickets.')->group(static function () use ($reference, $key, $uuid, $transition): void {
    Route::middleware(TicketsConfig::routeMiddleware())->group(static function () use ($reference, $key, $uuid, $transition): void {
        Route::get('tickets', [TicketController::class, 'index'])->name('index');
        Route::post('tickets', [TicketController::class, 'store'])->name('store');
        Route::post('tickets/bulk', BulkController::class)->name('bulk');

        Route::prefix('tickets/{ticket}')->where(['ticket' => $reference])->group(static function () use ($key, $uuid, $transition): void {
            Route::get('/', [TicketController::class, 'show'])->name('show');
            Route::patch('/', [TicketController::class, 'update'])->name('update');
            Route::delete('/', [TicketController::class, 'destroy'])->name('destroy');
            Route::post('restore', [TicketController::class, 'restore'])->name('restore');

            Route::get('transitions', [TransitionController::class, 'index'])->name('transitions.index');
            Route::post('transitions/{transition}', [TransitionController::class, 'store'])->where('transition', $transition)->name('transitions.store');

            Route::put('assignee', [AssignmentController::class, 'update'])->name('assignee.update');
            Route::delete('assignee', [AssignmentController::class, 'destroy'])->name('assignee.destroy');

            Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
            Route::post('replies', [CommentController::class, 'reply'])->name('replies.store');
            Route::post('notes', [CommentController::class, 'note'])->name('notes.store');
            Route::patch('comments/{comment}', [CommentController::class, 'update'])->where('comment', $key)->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->where('comment', $key)->name('comments.destroy');

            Route::get('fields', [FieldValueController::class, 'index'])->name('fields.index');
            Route::put('fields', [FieldValueController::class, 'update'])->name('fields.update');

            Route::get('watchers', [WatcherController::class, 'index'])->name('watchers.index');
            Route::post('watchers', [WatcherController::class, 'store'])->name('watchers.store');
            Route::delete('watchers/{watcher}', [WatcherController::class, 'destroy'])->where('watcher', '[a-z][a-z0-9_]{0,63}:[A-Za-z0-9-]{1,64}')->name('watchers.destroy');
            Route::post('watch', [WatcherController::class, 'watch'])->name('watch');
            Route::delete('watch', [WatcherController::class, 'unwatch'])->name('unwatch');

            Route::put('tags', [TagController::class, 'sync'])->name('tags.sync');

            Route::get('links', [LinkController::class, 'index'])->name('links.index');
            Route::post('links', [LinkController::class, 'store'])->name('links.store');
            Route::delete('links/{link}', [LinkController::class, 'destroy'])->where('link', $key)->name('links.destroy');

            Route::post('merge', MergeController::class)->name('merge');

            Route::get('attachments', [AttachmentController::class, 'index'])->name('attachments.index');
            Route::post('attachments', [AttachmentController::class, 'store'])->name('attachments.store');
            Route::get('attachments/{media}', [AttachmentController::class, 'show'])->where('media', $uuid)->name('attachments.show');
            Route::delete('attachments/{media}', [AttachmentController::class, 'destroy'])->where('media', $uuid)->name('attachments.destroy');

            Route::get('activity', ActivityController::class)->name('activity');
        });

        Route::get('ticket-categories', [CategoryController::class, 'index'])->name('admin.categories.index');
        Route::post('ticket-categories', [CategoryController::class, 'store'])->name('admin.categories.store');
        Route::get('ticket-categories/{category}', [CategoryController::class, 'show'])->where('category', $key)->name('admin.categories.show');
        Route::patch('ticket-categories/{category}', [CategoryController::class, 'update'])->where('category', $key)->name('admin.categories.update');
        Route::delete('ticket-categories/{category}', [CategoryController::class, 'destroy'])->where('category', $key)->name('admin.categories.destroy');
        Route::post('ticket-categories/{category}/move', [CategoryController::class, 'move'])->where('category', $key)->name('admin.categories.move');

        Route::get('ticket-fields', [FieldController::class, 'index'])->name('admin.fields.index');
        Route::post('ticket-fields', [FieldController::class, 'store'])->name('admin.fields.store');
        Route::get('ticket-fields/{field}', [FieldController::class, 'show'])->where('field', $key)->name('admin.fields.show');
        Route::patch('ticket-fields/{field}', [FieldController::class, 'update'])->where('field', $key)->name('admin.fields.update');
        Route::delete('ticket-fields/{field}', [FieldController::class, 'destroy'])->where('field', $key)->name('admin.fields.destroy');

        Route::get('ticket-tags', [TagAdminController::class, 'index'])->name('admin.tags.index');
        Route::post('ticket-tags', [TagAdminController::class, 'store'])->name('admin.tags.store');
        Route::patch('ticket-tags/{tag}', [TagAdminController::class, 'update'])->where('tag', $key)->name('admin.tags.update');
        Route::delete('ticket-tags/{tag}', [TagAdminController::class, 'destroy'])->where('tag', $key)->name('admin.tags.destroy');
    });

    if (TicketsConfig::guestsEnabled()) {
        Route::middleware([...TicketsConfig::guestRouteMiddleware(), EnsureGuestTicketAccess::class])
            ->prefix('tickets/guest/{ticket}')
            ->where(['ticket' => $reference])
            ->name('guest.')
            ->group(static function () use ($uuid, $transition): void {
                Route::get('/', [GuestTicketController::class, 'show'])->name('show');
                Route::post('replies', [GuestTicketController::class, 'reply'])->name('reply');
                Route::post('transitions/{transition}', [GuestTicketController::class, 'transition'])->where('transition', $transition)->name('transition');
                Route::get('attachments/{media}', [GuestTicketController::class, 'attachment'])->where('media', $uuid)->name('attachment');
            });
    }
});
