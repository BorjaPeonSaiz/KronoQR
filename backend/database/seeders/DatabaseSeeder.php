<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semilla de desarrollo (doc 02 §10.2).
 *
 * El objetivo del §10.2 es un conjunto de datos con casos limite incluidos
 * —turnos nocturnos, los dos cambios de hora, olvidos de salida, correcciones—
 * porque un dataset de datos "bonitos" oculta exactamente los errores que este
 * dominio produce. Ese objetivo se cumple al cerrar la Fase 2, no aqui: la
 * semilla se reparte entre las tareas que tienen delante el esquema que cada
 * trozo necesita.
 *
 * | Trozo                                                   | Tarea |
 * |---------------------------------------------------------|-------|
 * | Centros con zona horaria y departamentos base            | 0.1   |
 * | Empleados, credenciales, dispositivos, 90 dias de tramos | 1.3   |
 * | Casos limite: turnos nocturnos, DST, olvido de salida    | 1.4   |
 * | Correcciones y tramos superseded                         | 2.3   |
 *
 * El orden de ejecucion importa y esta fijado aqui: los departamentos cuelgan
 * de un centro, y los empleados de un departamento.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SiteSeeder::class,
            DepartmentSeeder::class,

            // Llegan con su tarea. Se dejan enumerados para que el orden de
            // ejecucion sea una decision tomada, no un descubrimiento.
            //
            // ComplianceProfileSeeder::class,  // 2.x — perfil ES-hosteleria
            // EmployeeSeeder::class,           // 1.3
            // CredentialSeeder::class,         // 1.3
            // DeviceSeeder::class,             // 1.3
            // VolumeSeeder::class,             // 1.3 — 90 dias de tramos
            // EdgeCaseSeeder::class,           // 1.4 — nocturnos, DST, olvidos
            // CorrectionSeeder::class,         // 2.3 — correcciones versionadas
        ]);
    }
}
