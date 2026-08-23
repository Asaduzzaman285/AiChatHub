<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'projects';

    protected $fillable = ['user_id', 'name', 'color'];

    public function sessions()
    {
        return $this->hasMany(ChatSession::class);
    }
}
