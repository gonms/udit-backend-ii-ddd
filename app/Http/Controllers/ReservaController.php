<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ExtraReserva;
use App\Models\Reserva;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * =====================================================================
 *  MATERIAL DOCENTE — CÓDIGO DELIBERADAMENTE MAL DISEÑADO
 * =====================================================================
 *  Este controlador es el punto de partida de la asignatura. Funciona
 *  (casi siempre), pasa una demo y probablemente lo hayáis visto en
 *  producción. NO es un ejemplo a seguir: es el paciente.
 *
 *  Ver docs/01-enunciado-clase-1.md
 * =====================================================================
 */
class ReservaController extends Controller
{
    const PRECIO_EXTRAS = [
        'silla_infantil'  => 4.50,
        'seguro_premium'  => 12.00,
        'gps'             => 3.00,
        'conductor_extra' => 8.75,
    ];

    public function index(Request $request)
    {
        $query = Reserva::with(['cliente', 'vehiculo', 'extras']);

        if ($request->has('cliente_id')) {
            $query->where('cliente_id', $request->input('cliente_id'));
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->has('desde')) {
            $query->where('fecha_inicio', '>=', $request->input('desde'));
        }

        $reservas = $query->orderBy('fecha_inicio', 'desc')->get();

        $resultado = [];
        foreach ($reservas as $r) {
            // Recalculamos el total por si acaso, que hubo un bug hace meses
            $dias = (strtotime($r->fecha_fin) - strtotime($r->fecha_inicio)) / 86400;
            $totalExtras = 0;
            foreach ($r->extras as $e) {
                $totalExtras += $e->precio_unitario * $e->cantidad;
            }

            $resultado[] = [
                'id'       => $r->id,
                'cliente'  => $r->cliente->nombre,
                'vehiculo' => $r->vehiculo->modelo . ' (' . $r->vehiculo->matricula . ')',
                'desde'    => $r->fecha_inicio,
                'hasta'    => $r->fecha_fin,
                'dias'     => $dias,
                'estado'   => $r->estado,
                'total'    => $r->total,
                'extras'   => $totalExtras,
                'cancelable' => $r->estado == 'confirmada'
                    && strtotime($r->fecha_inicio) - time() > 48 * 3600,
            ];
        }

        return response()->json(['data' => $resultado]);
    }

    public function store(Request $request)
    {
        // --- Validación de entrada ---
        if (!$request->filled('cliente_id') || !$request->filled('vehiculo_id')) {
            return response()->json(['error' => 'Faltan datos obligatorios'], 422);
        }
        if (!$request->filled('fecha_inicio') || !$request->filled('fecha_fin')) {
            return response()->json(['error' => 'Faltan las fechas'], 422);
        }
        if (strtotime($request->input('fecha_fin')) <= strtotime($request->input('fecha_inicio'))) {
            return response()->json(['error' => 'La fecha de fin debe ser posterior al inicio'], 422);
        }
        if (strtotime($request->input('fecha_inicio')) < strtotime('today')) {
            return response()->json(['error' => 'No se puede reservar en el pasado'], 422);
        }

        $cliente = Cliente::find($request->input('cliente_id'));
        if (!$cliente) {
            return response()->json(['error' => 'Cliente no encontrado'], 404);
        }
        if ($cliente->bloqueado) {
            return response()->json(['error' => 'El cliente está bloqueado'], 403);
        }

        // --- Licencia de conducir en vigor ---
        if (strtotime($cliente->licencia_caduca_el) < strtotime($request->input('fecha_fin'))) {
            return response()->json([
                'error' => 'La licencia del cliente caduca antes del fin de la reserva'
            ], 422);
        }

        $vehiculo = Vehiculo::find($request->input('vehiculo_id'));
        if (!$vehiculo) {
            return response()->json(['error' => 'Vehículo no encontrado'], 404);
        }
        if ($vehiculo->estado == 'taller') {
            return response()->json(['error' => 'El vehículo está en mantenimiento'], 409);
        }

        // --- Edad mínima según categoría ---
        $edad = date('Y') - date('Y', strtotime($cliente->fecha_nacimiento));
        if ($edad < 21) {
            return response()->json(['error' => 'Edad mínima 21 años'], 403);
        }
        if ($vehiculo->categoria == 'premium' && $edad < 25) {
            return response()->json(['error' => 'Los vehículos premium requieren 25 años'], 403);
        }

        // --- Comprobación de disponibilidad ---
        $solapadas = Reserva::where('vehiculo_id', $vehiculo->id)
            ->whereIn('estado', ['pendiente', 'confirmada', 'en_curso'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('fecha_inicio', [
                    $request->input('fecha_inicio'),
                    $request->input('fecha_fin'),
                ])->orWhereBetween('fecha_fin', [
                    $request->input('fecha_inicio'),
                    $request->input('fecha_fin'),
                ]);
            })
            ->count();

        if ($solapadas > 0) {
            return response()->json(['error' => 'El vehículo no está disponible en esas fechas'], 409);
        }

        // --- Límite de reservas simultáneas por cliente ---
        $activas = Reserva::where('cliente_id', $cliente->id)->count();
        if ($activas >= 3) {
            return response()->json(['error' => 'Máximo 3 reservas activas por cliente'], 409);
        }

        // --- Cálculo del precio ---
        $dias = (strtotime($request->input('fecha_fin')) - strtotime($request->input('fecha_inicio'))) / 86400;
        $total = $vehiculo->tarifa_dia * $dias;

        // Temporada alta: julio y agosto, +20%
        $mes = (int) date('n', strtotime($request->input('fecha_inicio')));
        if ($mes == 7 || $mes == 8) {
            $total = $total * 1.2;
        }

        // Descuento por estancia larga
        if ($dias > 7) {
            $total = $total - ($total * 0.1);
        }

        $extrasGuardar = [];
        foreach ((array) $request->input('extras', []) as $codigo => $cantidad) {
            if (!isset(self::PRECIO_EXTRAS[$codigo])) {
                return response()->json(['error' => 'Extra desconocido: ' . $codigo], 422);
            }
            if ($codigo == 'conductor_extra' && $edad < 25) {
                return response()->json(['error' => 'Conductor extra no disponible para menores de 25'], 403);
            }
            $precio = self::PRECIO_EXTRAS[$codigo];
            $total += $precio * $cantidad * $dias;
            $extrasGuardar[] = [
                'codigo'          => $codigo,
                'cantidad'        => $cantidad,
                'precio_unitario' => $precio,
            ];
        }

        // --- Persistencia ---
        $reserva = new Reserva();
        $reserva->cliente_id   = $cliente->id;
        $reserva->vehiculo_id  = $vehiculo->id;
        $reserva->fecha_inicio = $request->input('fecha_inicio');
        $reserva->fecha_fin    = $request->input('fecha_fin');
        $reserva->estado       = 'pendiente';
        $reserva->total        = $total;
        $reserva->save();

        foreach ($extrasGuardar as $e) {
            $extra = new ExtraReserva();
            $extra->reserva_id      = $reserva->id;
            $extra->codigo          = $e['codigo'];
            $extra->cantidad        = $e['cantidad'];
            $extra->precio_unitario = $e['precio_unitario'];
            $extra->save();
        }

        $vehiculo->estado = 'reservado';
        $vehiculo->save();

        // --- Efectos secundarios ---
        Mail::raw(
            "Hola {$cliente->nombre},\n\n" .
            "Hemos registrado tu reserva #{$reserva->id} del {$reserva->fecha_inicio} " .
            "al {$reserva->fecha_fin} por {$total} EUR.\n\n" .
            "Recuerda confirmarla en las próximas 24 horas.\n\nEl equipo de flota",
            function ($m) use ($cliente) {
                $m->to($cliente->email)->subject('Tu reserva está registrada');
            }
        );

        // Avisamos al sistema de telemetría para que precaliente el vehículo
        Http::post('https://telemetria.example.com/api/v1/vehiculos/asignar', [
            'matricula'  => $vehiculo->matricula,
            'reserva_id' => $reserva->id,
            'desde'      => $reserva->fecha_inicio,
            'hasta'      => $reserva->fecha_fin,
        ]);

        Log::info('Reserva creada', ['id' => $reserva->id, 'total' => $total]);

        return response()->json([
            'id'     => $reserva->id,
            'estado' => $reserva->estado,
            'total'  => $reserva->total,
            'dias'   => $dias,
        ], 201);
    }

    public function confirmar($id)
    {
        $reserva = Reserva::find($id);
        if (!$reserva) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        if ($reserva->estado == 'cancelada') {
            return response()->json(['error' => 'La reserva está cancelada'], 409);
        }
        if ($reserva->estado == 'confirmada') {
            return response()->json(['error' => 'Ya estaba confirmada'], 409);
        }

        $reserva->estado = 'confirmada';
        $reserva->confirmada_en = now();
        $reserva->save();

        $cliente = Cliente::find($reserva->cliente_id);
        Mail::raw(
            "Hola {$cliente->nombre},\n\nTu reserva #{$reserva->id} está confirmada.",
            function ($m) use ($cliente) {
                $m->to($cliente->email)->subject('Reserva confirmada');
            }
        );

        Http::post('https://telemetria.example.com/api/v1/vehiculos/desbloquear-app', [
            'reserva_id' => $reserva->id,
        ]);

        Log::info('Reserva confirmada', ['id' => $reserva->id]);

        return response()->json(['ok' => true, 'estado' => $reserva->estado]);
    }

    public function iniciar($id)
    {
        $reserva = Reserva::find($id);
        if (!$reserva) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }
        if ($reserva->estado != 'confirmada') {
            return response()->json(['error' => 'Solo se pueden iniciar reservas confirmadas'], 409);
        }

        $reserva->estado = 'encurso';
        $reserva->save();

        return response()->json(['ok' => true, 'estado' => $reserva->estado]);
    }

    public function cancelar(Request $request, $id)
    {
        $reserva = Reserva::find($id);
        if (!$reserva) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        if ($reserva->estado == 'cancelada') {
            return response()->json(['error' => 'Ya estaba cancelada'], 409);
        }
        if ($reserva->estado == 'finalizada') {
            return response()->json(['error' => 'No se puede cancelar una reserva finalizada'], 409);
        }

        // --- Penalización por cancelación ---
        $horasHastaInicio = (strtotime($reserva->fecha_inicio) - time()) / 3600;

        if ($horasHastaInicio > 48) {
            $penalizacion = 0;
        } elseif ($horasHastaInicio > 24) {
            $penalizacion = $reserva->total * 0.3;
        } else {
            $penalizacion = $reserva->total;
        }

        $reserva->estado = 'cancelada';
        $reserva->penalizacion = $penalizacion;
        $reserva->motivo_cancelacion = $request->input('motivo', 'Sin especificar');
        $reserva->save();

        $vehiculo = Vehiculo::find($reserva->vehiculo_id);
        $vehiculo->estado = 'disponible';
        $vehiculo->save();

        $cliente = Cliente::find($reserva->cliente_id);
        Mail::raw(
            "Hola {$cliente->nombre},\n\nTu reserva #{$reserva->id} ha sido cancelada.\n" .
            "Penalización aplicada: {$penalizacion} EUR.",
            function ($m) use ($cliente) {
                $m->to($cliente->email)->subject('Reserva cancelada');
            }
        );

        if ($penalizacion > 0) {
            Http::post('https://pagos.example.com/api/v1/cargos', [
                'cliente_email' => $cliente->email,
                'importe'       => $penalizacion,
                'concepto'      => 'Penalización por cancelación de reserva ' . $reserva->id,
            ]);
        }

        Http::post('https://telemetria.example.com/api/v1/vehiculos/liberar', [
            'matricula' => $vehiculo->matricula,
        ]);

        Log::info('Reserva cancelada', ['id' => $reserva->id, 'penalizacion' => $penalizacion]);

        return response()->json([
            'ok'           => true,
            'penalizacion' => $penalizacion,
        ]);
    }

    public function disponibilidad(Request $request)
    {
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');
        $categoria = $request->input('categoria');

        $vehiculos = Vehiculo::where('estado', '!=', 'taller')->get();

        $disponibles = [];
        foreach ($vehiculos as $v) {
            if ($categoria && $v->categoria != $categoria) {
                continue;
            }

            $ocupado = false;
            foreach ($v->reservas as $r) {
                if (in_array($r->estado, ['pendiente', 'confirmada', 'en_curso'])) {
                    if (strtotime($r->fecha_inicio) <= strtotime($hasta)
                        && strtotime($r->fecha_fin) >= strtotime($desde)) {
                        $ocupado = true;
                        break;
                    }
                }
            }

            if (!$ocupado) {
                $dias = (strtotime($hasta) - strtotime($desde)) / 86400;
                $total = $v->tarifa_dia * $dias;
                $mes = (int) date('n', strtotime($desde));
                if ($mes == 7 || $mes == 8) {
                    $total = $total * 1.2;
                }
                if ($dias > 7) {
                    $total = $total - ($total * 0.1);
                }

                $disponibles[] = [
                    'id'        => $v->id,
                    'modelo'    => $v->modelo,
                    'categoria' => $v->categoria,
                    'base'      => $v->base,
                    'total'     => $total,
                ];
            }
        }

        return response()->json(['data' => $disponibles]);
    }
}
