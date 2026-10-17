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
        // Guests are attribute owners and requester/watcher targets, so their key follows
        // the inbound `primary_key_type`. Every field lives in the attributes table.
        $primaryKeyType = KeyType::fromConfig('tickets.primary_key_type');

        Schema::create('ticket_guests', function (Blueprint $table) use ($primaryKeyType): void {
            match ($primaryKeyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->timestamps();
            $table->softDeletes();
        });
    }
};
