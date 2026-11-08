<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Actividad extends Model
{
    use HasFactory;

    protected $table = 'actividades';

    protected $primaryKey = 'id';

    // La tabla utiliza fecha_registro en lugar de created_at
    const CREATED_AT = 'fecha_registro';

    // La tabla no tiene columna updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'estatus',
    ];

    protected $casts = [
        'fecha_registro' => 'datetime',
    ];
}
