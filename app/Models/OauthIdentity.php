<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
