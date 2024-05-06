<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = [
        "academicLevel",
        "establishment",
        "startDate",
        "gender",
        "endDate",
        "profile_id"
    ];
    public function profile(){
        return $this->belongsTo(Profile::class);
    }
    public function demands(){
        return $this->hasMany(Demand::class);
    }
}
