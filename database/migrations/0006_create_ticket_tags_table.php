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

        Schema::create('ticket_tags', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('project', $keyType, nullable: true);
            $table->string('name', 64);
            $table->string('slug', 80);
            $table->string('color', 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Binds project tags; global tags (NULL scope) are serialised by `lock:tags`.
            $table->uniqueSlug('slug', ['project_type', 'project_id']);
        });
    }
};
