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
        $keyType = KeyType::fromConfig('tickets.key_type');

        Schema::create('ticket_fields', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('project', $keyType, nullable: true);
            // Categories soft-delete, so the cascade only fires on a force delete.
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->cascadeOnDelete();
            // Unique among live rows per project, counting global fields and every category —
            // enforced by the action under the `lock:fields` advisory row (no portable
            // partial unique index exists).
            $table->string('key', 64)->index();
            // Locale maps (translatable-for-laravel's macro: a jsonb column).
            $table->translatable('label');
            $table->translatable('help')->nullable();
            $table->string('type', 32);
            $table->jsonb('options')->nullable();
            $table->jsonb('rules')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('encrypted')->default(false);
            $table->jsonb('default')->nullable();
            $table->boolean('requester_visible')->default(true);
            $table->boolean('requester_editable')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
