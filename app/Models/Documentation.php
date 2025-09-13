<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documentation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'url',
        'uri',
        'note',
        'status',
        'creation_date',
    ];
    protected $dateFormat = 'Y-m-d H:i:s';
}
