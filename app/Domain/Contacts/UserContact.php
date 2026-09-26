<?php

namespace App\Domain\Contacts;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class UserContact extends Model
{
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contact()
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}
