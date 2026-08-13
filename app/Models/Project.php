<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'slug', 'repository', 'color', 'description'];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
