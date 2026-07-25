<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->nullable();
            $table->string('host');
            $table->unsignedInteger('port')->default(3306);
            $table->string('username');
            $table->text('password')->nullable();
            $table->boolean('use_ssh')->default(false);
            $table->string('ssh_host')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('ssh_username')->nullable();
            $table->string('ssh_auth_type')->default('password');
            $table->text('ssh_password')->nullable();
            $table->string('ssh_key_path')->nullable();
            $table->string('default_database')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
