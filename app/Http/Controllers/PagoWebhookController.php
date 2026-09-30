<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Reserva;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MATERIAL DOCENTE — CÓDIGO DELIBERADAMENTE MAL DISEÑADO
 *
 * Este webhook lo añadió otro compañero seis meses después del
 * ReservaController, cuando la pasarela de pagos empezó a notificar
 * impagos. Copió la lógica de cancelación "adaptándola un poco".
 */
class PagoWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $evento = $request->input('event');
        $reservaId = $request->input('data.metadata.reserva_id');

        if ($evento == 'payment.failed') {
            $reserva = Reserva::find($reservaId);
            if (!$reserva) {
                return response()->json(['ok' => true]);
            }

            if ($reserva->estado == 'cancelada' || $reserva->estado == 'finalizada') {
                return response()->json(['ok' => true]);
            }

            // Penalización por cancelación (misma regla que en ReservaController)
            $horas = (strtotime($reserva->fecha_inicio) - time()) / 3600;
            if ($horas > 72) {
                $penalizacion = 0;
            } elseif ($horas > 48) {
                $penalizacion = $reserva->total * 0.25;
            } else {
                $penalizacion = $reserva->total;
            }

            $reserva->estado = 'cancelada';
            $reserva->penalizacion = $penalizacion;
            $reserva->motivo_cancelacion = 'Impago';
            $reserva->save();

            $vehiculo = Vehiculo::find($reserva->vehiculo_id);
            $vehiculo->estado = 'disponible';
            $vehiculo->save();

            $cliente = Cliente::find($reserva->cliente_id);
            Mail::raw(
                "Hola {$cliente->nombre},\n\nHemos cancelado tu reserva #{$reserva->id} " .
                "por impago. Penalización: {$penalizacion} EUR.",
                function ($m) use ($cliente) {
                    $m->to($cliente->email)->subject('Reserva cancelada por impago');
                }
            );

            Log::warning('Reserva cancelada por impago', ['id' => $reserva->id]);
        }

        if ($evento == 'payment.succeeded') {
            $reserva = Reserva::find($reservaId);
            if ($reserva && $reserva->estado == 'pendiente') {
                $reserva->estado = 'confirmada';
                $reserva->confirmada_en = now();
                $reserva->save();

                $cliente = Cliente::find($reserva->cliente_id);
                Mail::raw(
                    "Hola {$cliente->nombre},\n\nPago recibido. Reserva #{$reserva->id} confirmada.",
                    function ($m) use ($cliente) {
                        $m->to($cliente->email)->subject('Reserva confirmada');
                    }
                );
            }
        }

        return response()->json(['ok' => true]);
    }
}
