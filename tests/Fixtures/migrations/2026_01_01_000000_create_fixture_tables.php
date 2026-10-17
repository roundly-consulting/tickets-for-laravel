<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_agent')->default(false);
            $table->timestamps();
        });

        // The people of a uuid installation (the uuid key leg).
        Schema::create('uuid_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_agent')->default(false);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->nullable();
            $table->timestamps();
        });

        // A host comments table from before comments 1.1 — no `meta` column.
        Schema::create('legacy_comments', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key', 16);
            $table->timestamps();
        });
    }
};
