<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property string $color
 * @property string|null $description
 */
class Project extends Model
{
    protected $fillable = ['name', 'slug', 'path', 'color', 'description'];
}
