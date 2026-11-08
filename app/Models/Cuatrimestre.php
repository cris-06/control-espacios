<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $numero
 * @property string $nombre
 */
class Cuatrimestre extends Model
{
    protected $table = 'cuatrimestres';

    public $timestamps = false;
}
