<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $guarded = [];

    public function reservas()
    {
        return $this->hasMany(Reserva::class);
    }
}
