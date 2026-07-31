<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('id');
        });

        $ids = DB::table('connections')->orderBy('name')->pluck('id');
        foreach ($ids as $index => $id) {
            DB::table('connections')->where('id', $id)->update(['sort_order' => $index]);
        }
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
