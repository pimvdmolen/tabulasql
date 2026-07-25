<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryHistory extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'query_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'duration_ms' => 'integer',
            'rows_affected' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
