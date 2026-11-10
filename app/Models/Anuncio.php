<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anuncio extends Model
{
    protected $table = 'anuncios';
    public $timestamps = false;

    protected $fillable = ['admin_id', 'titulo', 'contenido', 'imagen', 'estatus'];

    protected $casts = [
        'fecha_publicacion' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
