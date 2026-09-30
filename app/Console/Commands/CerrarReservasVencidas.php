<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Reserva;
use App\Models\Vehiculo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * MATERIAL DOCENTE — CÓDIGO DELIBERADAMENTE MAL DISEÑADO
 *
 * Cron nocturno. Hace dos cosas a la vez porque "ya que recorremos
 * la tabla": caduca las reservas pendientes sin confirmar y cierra
 * las que ya terminaron.
 */
class CerrarReservasVencidas extends Command
{
    protected $signature = 'reservas:cerrar-vencidas';

    protected $description = 'Caduca reservas pendientes y cierra las finalizadas';

    public function handle()
    {
        // 1) Reservas pendientes que llevan más de 24h sin confirmar
        $pendientes = Reserva::where('estado', 'pendiente')->get();

        foreach ($pendientes as $reserva) {
            $horasDesdeCreacion = (time() - strtotime($reserva->created_at)) / 3600;

            if ($horasDesdeCreacion > 24) {
                // Penalización por cancelación
                $horasHastaInicio = (strtotime($reserva->fecha_inicio) - time()) / 3600;
                $penalizacion = $horasHastaInicio > 24 ? 0 : $reserva->total;

                $reserva->estado = 'cancelada';
                $reserva->penalizacion = $penalizacion;
                $reserva->motivo_cancelacion = 'No confirmada en plazo';
                $reserva->save();

                $vehiculo = Vehiculo::find($reserva->vehiculo_id);
                $vehiculo->estado = 'disponible';
                $vehiculo->save();

                $cliente = Cliente::find($reserva->cliente_id);
                Mail::raw(
                    "Hola {$cliente->nombre},\n\nTu reserva #{$reserva->id} ha caducado " .
                    "por no confirmarse en 24 horas.",
                    function ($m) use ($cliente) {
                        $m->to($cliente->email)->subject('Reserva caducada');
                    }
                );

                $this->info("Reserva {$reserva->id} caducada");
            }
        }

        // 2) Reservas en curso cuya fecha de fin ya pasó
        $enCurso = Reserva::where('estado', 'en_curso')->get();

        foreach ($enCurso as $reserva) {
            if (strtotime($reserva->fecha_fin) < strtotime('today')) {
                $reserva->estado = 'finalizada';
                $reserva->save();

                $vehiculo = Vehiculo::find($reserva->vehiculo_id);
                $vehiculo->estado = 'disponible';
                $vehiculo->kilometraje = $vehiculo->kilometraje + 250; // media estimada
                $vehiculo->save();

                $this->info("Reserva {$reserva->id} finalizada");
            }
        }

        return self::SUCCESS;
    }
}
