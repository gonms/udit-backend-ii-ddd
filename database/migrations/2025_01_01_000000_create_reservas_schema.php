<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATENCIÓN (material docente): este esquema contiene decisiones
 * deliberadamente cuestionables. Ver docs/02-solucion-profesor.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email');
            $table->string('telefono')->nullable();
            $table->date('fecha_nacimiento');
            $table->string('numero_licencia');
            $table->date('licencia_caduca_el');
            $table->boolean('bloqueado')->default(false);
            $table->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->string('matricula');
            $table->string('modelo');
            $table->string('categoria');          // 'basico' | 'familiar' | 'premium'
            $table->float('tarifa_dia');
            $table->string('estado')->default('disponible'); // 'disponible' | 'reservado' | 'taller'
            $table->integer('kilometraje')->default(0);
            $table->date('proxima_revision')->nullable();
            $table->string('base');               // ciudad donde está estacionado
            $table->timestamps();
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cliente_id');
            $table->unsignedBigInteger('vehiculo_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado')->default('pendiente');
            $table->float('total');
            $table->float('penalizacion')->nullable();
            $table->string('motivo_cancelacion')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamps();
        });

        Schema::create('extras_reserva', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->string('codigo');   // 'silla_infantil' | 'seguro_premium' | 'gps' | 'conductor_extra'
            $table->integer('cantidad')->default(1);
            $table->float('precio_unitario');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras_reserva');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('clientes');
    }
};
