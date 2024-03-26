<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
class subscription extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'subscription';
}
