<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // When set, this connection is restricted to a single database:
            // the tree only ever shows this one, instead of every database
            // on the server. Mutually exclusive with default_database.
            $table->string('database')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('database');
        });
    }
};
