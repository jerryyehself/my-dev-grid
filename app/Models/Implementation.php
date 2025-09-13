<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Implementation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'sub_title',
        'description',
        'url',
        'git_repo_id',
        'is_visible',
        'maintain_status',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';
}
