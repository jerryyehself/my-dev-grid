<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Technique extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'version',
        'note',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';
}
