<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sport_id
 * @property string $name
 */
class Team extends Model
{
    protected $guarded = ['id'];
}
