<?php

namespace App\Models;

use Database\Factories\OauthIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OauthIdentity extends Model
{
    /** @use HasFactory<OauthIdentityFactory> */
    use HasFactory;

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
