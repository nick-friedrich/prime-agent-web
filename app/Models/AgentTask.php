<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTask extends Model
{
    protected $fillable = ['agent_id', 'title', 'status', 'progress'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
