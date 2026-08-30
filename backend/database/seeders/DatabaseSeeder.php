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
 * | Casos limite: turnos nocturnos, DST, olvido de salida    | 1.4 ✔ |
 * | Correcciones y tramos superseded                         | 1.15 ✔|
 *
 * El orden de ejecucion importa y esta fijado aqui: los departamentos cuelgan
 * de un centro, los empleados de un departamento, las credenciales de un
 * empleado y los tramos de todo lo anterior.
 *
 * **El perfil de cumplimiento `ES-hosteleria` y los umbrales operativos del
 * Anexo B no estan en esta lista y no es un olvido**: los siembran sus
 * migraciones. Un seeder no se ejecuta en la instalacion de un cliente, y sin
 * esos dos conjuntos de valores el primer calculo de jornada no tendria umbral
 * que aplicar (regla dura 14). Son dato de producto, no dato de desarrollo.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SiteSeeder::class,
            DepartmentSeeder::class,
            UserSeeder::class,
            // Reparte entre esas cuentas los seis roles de RF-ID-02, que crea
            // su migracion porque son dato de producto y no de desarrollo.
            RoleSeeder::class,
            EmployeeSeeder::class,

            // Contratos historizados (RF-GP-02, tarea 2.8). Justo despues de la
            // plantilla y antes de las jornadas: sin contrato, el informe de
            // trabajadas frente a contratadas (RF-IN-03) saldria con lo
            // contratado en cero para todo el mundo y con el aviso de cobertura
            // cubriendo la plantilla entera, que es justo lo que no se quiere
            // ver en un entorno de desarrollo.
            EmploymentContractSeeder::class,

            CredentialSeeder::class,
            DeviceSeeder::class,
            VolumeSeeder::class,

            // Despues del volumen y no antes: `VolumeSeeder` se salta el trabajo
            // si ya hay tramos, y los casos limite son tramos. Ademas usa
            // empleados propios, de modo que el turno olvidado —abierto, y por
            // tanto con rango sin fin— no puede solapar con las jornadas
            // aburridas de nadie (`shift_entries_no_overlap`).
            EdgeCaseSeeder::class,

            // Correcciones y tramos `superseded` (tarea 1.15). Va el ultimo y con
            // empleados propios por el mismo motivo que los casos limite: siembra
            // dos tramos que SE SOLAPAN a proposito —la version anterior y la
            // corregida— y solo caben en la tabla porque uno de los dos ya no es
            // vigente (ADR-026).
            CorrectionSeeder::class,
        ]);
    }
}
