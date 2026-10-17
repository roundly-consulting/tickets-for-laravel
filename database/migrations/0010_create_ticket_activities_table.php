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
        $primaryKeyType = KeyType::fromConfig('tickets.primary_key_type');
        $keyType = KeyType::fromConfig('tickets.key_type');

        // Append-only: no updated_at, no soft deletes; a force-deleted ticket cascades.
        Schema::create('ticket_activities', function (Blueprint $table) use ($primaryKeyType, $keyType): void {
            $table->id();
            $table->ownerKey('ticket_id', $primaryKeyType, index: false);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->string('type', 48);
            $table->morphKey('actor', $keyType, nullable: true);
            $table->boolean('is_system')->default(false);
            $table->string('visibility', 16);
            $table->jsonb('properties')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['ticket_id', 'occurred_at']);
        });
    }
};
