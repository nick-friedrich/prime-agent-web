<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('model')->default('Prime RLM');
            $table->enum('status', ['running', 'idle', 'paused', 'error'])->default('idle');
            $table->text('goal')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
