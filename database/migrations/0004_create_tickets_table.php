<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        // The ticket's own id is what lifecycle, comments, attributes and media point at.
        $primaryKeyType = KeyType::fromConfig('tickets.primary_key_type');
        // Requester, assignee and project are host models.
        $keyType = KeyType::fromConfig('tickets.key_type');

        Schema::create('tickets', function (Blueprint $table) use ($primaryKeyType, $keyType): void {
            // An explicit primary() command rather than the fluent modifier: fluent indexes
            // are emitted after every explicit command, and Postgres refuses the
            // self-referencing `merged_into_id` foreign key below until `id` is unique.
            match ($primaryKeyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id'),
                KeyType::Ulid => $table->ulid('id'),
            };

            if ($primaryKeyType !== KeyType::BigInt) {
                $table->primary('id');
            }

            // Identity: the stored, human reference (`SUP-42`) is the route key.
            $table->string('reference', 32)->unique();
            $table->unsignedBigInteger('number');
            $table->string('sequence_scope', 191);

            // Content.
            $table->string('subject', 255);
            $table->longText('description')->nullable();

            // State — `status` is owned by lifecycle and never written by tickets.
            $table->string('status', 64)->nullable()->index();
            $table->string('priority', 32)->index();
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();

            // Parties.
            $table->morphKey('requester', $keyType);
            $table->morphKey('assignee', $keyType, nullable: true);
            $table->timestamp('assigned_at')->nullable();
            $table->morphKey('project', $keyType, nullable: true);
            $table->string('channel', 32);

            // Timing.
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();

            // Merge and guest access.
            $table->ownerKey('merged_into_id', $primaryKeyType, nullable: true);
            $table->foreign('merged_into_id')->references('id')->on('tickets')->nullOnDelete();
            $table->unsignedInteger('guest_access_version')->default(1);

            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sequence_scope', 'number']);
            $table->index(['assignee_type', 'assignee_id', 'status'], 'tickets_assignee_status_index');
            $table->index(['requester_type', 'requester_id', 'created_at'], 'tickets_requester_created_index');
            $table->index(['project_type', 'project_id', 'status'], 'tickets_project_status_index');
        });
    }
};
