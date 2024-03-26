<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
class payment extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'payment';
}
