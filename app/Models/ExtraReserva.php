<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraReserva extends Model
{
    protected $table = 'extras_reserva';

    protected $guarded = [];

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }
}
