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

        Schema::create('ticket_watchers', function (Blueprint $table) use ($primaryKeyType, $keyType): void {
            $table->id();
            $table->ownerKey('ticket_id', $primaryKeyType, index: false);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->morphKey('watcher', $keyType);
            $table->timestamp('created_at')->nullable();

            $table->unique(['ticket_id', 'watcher_type', 'watcher_id']);
        });
    }
};
