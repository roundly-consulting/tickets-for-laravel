<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reference sequence (`global`, `{alias}:{key}`) plus the fixed
        // `lock:*` advisory rows that serialise writes no portable unique index can hold.
        Schema::create('ticket_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191)->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }
};
