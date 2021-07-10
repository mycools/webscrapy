<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
class WebsiteChannel extends Model
{
    use HasFactory, SoftDeletes;

    public function lists()
    {
        return $this->hasMany(\App\Models\WebsiteList::class,'sub_channel','name');
    }
}
