<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Cliente::create([
            'nombre' => 'Ana Ferreira', 'email' => 'ana@example.com',
            'fecha_nacimiento' => '1990-03-14', 'numero_licencia' => 'B-4471928',
            'licencia_caduca_el' => '2031-03-14',
        ]);

        // Cumple 25 años en diciembre: útil para el bug de cálculo de edad
        Cliente::create([
            'nombre' => 'Bruno Salas', 'email' => 'bruno@example.com',
            'fecha_nacimiento' => date('Y') - 25 . '-12-20', 'numero_licencia' => 'B-9930112',
            'licencia_caduca_el' => '2027-12-20',
        ]);

        Cliente::create([
            'nombre' => 'Carla Nunes', 'email' => 'carla@example.com',
            'fecha_nacimiento' => '1978-07-02', 'numero_licencia' => 'B-1120334',
            'licencia_caduca_el' => date('Y') . '-10-31',   // caduca pronto
        ]);

        Vehiculo::create([
            'matricula' => '4471-KLM', 'modelo' => 'Renault Zoe', 'categoria' => 'basico',
            'tarifa_dia' => 34.90, 'base' => 'Vigo',
        ]);
        Vehiculo::create([
            'matricula' => '8812-BCD', 'modelo' => 'Dacia Jogger', 'categoria' => 'familiar',
            'tarifa_dia' => 52.50, 'base' => 'Porto',
        ]);
        Vehiculo::create([
            'matricula' => '1190-XYZ', 'modelo' => 'Tesla Model 3', 'categoria' => 'premium',
            'tarifa_dia' => 89.90, 'base' => 'Porto',
        ]);
        Vehiculo::create([
            'matricula' => '5567-FGH', 'modelo' => 'Fiat 500e', 'categoria' => 'basico',
            'tarifa_dia' => 31.00, 'base' => 'Vigo', 'estado' => 'taller',
        ]);
    }
}
