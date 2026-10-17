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
        // Outbound morphs (the project, the default assignee) point at host models.
        $keyType = KeyType::fromConfig('tickets.key_type');

        Schema::create('ticket_categories', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->morphKey('project', $keyType, nullable: true);
            // Locale maps — the same jsonb column translatable's `translatable()` macro emits.
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('slug', 191);
            $table->string('default_priority', 32)->nullable();
            // A morph spelled out by hand: `morphKey('default_assignee')` would name its index
            // past MySQL's 64-character limit. The id still goes through the key-type seam.
            $table->string('default_assignee_type')->nullable();
            $table->ownerKey('default_assignee_id', $keyType, nullable: true, index: false);
            $table->string('default_due_in', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['default_assignee_type', 'default_assignee_id'], 'ticket_categories_default_assignee_index');
            $table->index(['project_type', 'project_id', 'parent_id', 'position'], 'ticket_categories_tree_index');
            // A plain composite unique: it only binds rows whose project AND parent are set
            // (NULLs are distinct on every engine). Global and root categories rely on
            // sluggable's probe, serialised by the `lock:categories` advisory row. No
            // `deleted_at`: a trashed category keeps its slug taken.
            $table->uniqueSlug('slug', ['project_type', 'project_id', 'parent_id']);
        });
    }
};
