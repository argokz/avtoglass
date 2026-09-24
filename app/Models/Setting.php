<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
