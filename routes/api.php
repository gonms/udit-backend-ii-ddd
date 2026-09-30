<?php

use App\Http\Controllers\PagoWebhookController;
use App\Http\Controllers\ReservaController;
use Illuminate\Support\Facades\Route;

Route::get('/reservas', [ReservaController::class, 'index']);
Route::post('/reservas', [ReservaController::class, 'store']);
Route::post('/reservas/{id}/confirmar', [ReservaController::class, 'confirmar']);
Route::post('/reservas/{id}/iniciar', [ReservaController::class, 'iniciar']);
Route::post('/reservas/{id}/cancelar', [ReservaController::class, 'cancelar']);
Route::get('/disponibilidad', [ReservaController::class, 'disponibilidad']);

Route::post('/webhooks/pagos', [PagoWebhookController::class, 'handle']);
