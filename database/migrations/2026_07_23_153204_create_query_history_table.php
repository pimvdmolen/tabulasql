<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('database')->nullable();
            $table->text('query');
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->bigInteger('rows_affected')->default(0);
            $table->timestamp('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_history');
    }
};
