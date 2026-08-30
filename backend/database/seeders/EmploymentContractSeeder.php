<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Contratos de la plantilla de desarrollo (RF-GP-02, tarea 2.8).
 *
 * ## Para que existe
 *
 * Sin contrato no hay comparativa de trabajadas frente a contratadas (RF-IN-03):
 * el informe saldria con `contracted_minutes` en cero para todo el mundo y con
 * un aviso de cobertura que cubriria la plantilla entera. Con esto, `make up` da
 * un entorno donde la pantalla de informes enseña lo que va a enseñar en casa
 * del cliente.
 *
 * ## Tres formas distintas a proposito
 *
 * Una semilla en la que todo el mundo tiene 40 h no prueba nada:
 *
 *   - **La mayoria, un contrato abierto** con jornada completa o parcial. Es el
 *     caso normal.
 *   - **Uno de cada siete, un cambio a mitad del periodo**: un contrato cerrado
 *     y otro abierto que empieza al dia siguiente. Es lo que hace visible que el
 *     prorrateo mira el contrato **vigente cada dia** y no el ultimo, y es el
 *     escenario de la prueba de RF-IN-03.
 *   - **Uno de cada trece, sin contrato**: para que `meta.contract_coverage` no
 *     sea siempre cero y el aviso del panel se vea alguna vez. Un informe que
 *     nunca enseña su propio hueco es un informe cuyo hueco nadie descubre hasta
 *     que importa.
 *
 * ## Determinista
 *
 * El reparto sale del **indice** de cada empleado y no de `rand()`: dos
 * ejecuciones de la semilla dan la misma plantilla, y una prueba que se apoye en
 * ella no falla un dia de cada siete.
 *
 * ## No pasa por el caso de uso
 *
 * Escribe directamente con `DB::table()`, como el resto de los seeders. No es
 * una excepcion a «el caso de uso abre la transaccion»: un seeder no es una
 * peticion, no hay actor que audite y hacer pasar seiscientos contratos por
 * `RegisterEmploymentContract` significaria seiscientos asientos de
 * `audit_log` que describen un hecho que nunca ocurrio en ninguna empresa.
 */
final class EmploymentContractSeeder extends Seeder
{
    /** Horas semanales que se reparten, en el orden en que se asignan. */
    private const array WEEKLY_HOURS = [40.0, 37.5, 30.0, 20.0];

    /** Los tres valores del catalogo del doc 01 §5.5. */
    private const array SCHEDULE_TYPES = ['turnos', 'continua', 'partida'];

    /** Uno de cada N cambia de contrato a mitad de la ventana sembrada. */
    private const int CHANGE_EVERY = 7;

    /** Uno de cada N se queda sin contrato, para que el aviso de cobertura se vea. */
    private const int WITHOUT_CONTRACT_EVERY = 13;

    /**
     * Dias hacia atras desde hoy en los que arranca la serie de contratos.
     *
     * Cubre con margen los 90 dias de `VolumeSeeder`: un contrato que empezara
     * despues de la primera jornada sembrada dejaria dias «sin contrato» que no
     * describen nada, solo el orden en que se escribieron las semillas.
     */
    private const int HISTORY_DAYS = 200;

    public function run(): void
    {
        if (DB::table('employment_contracts')->exists()) {
            // Idempotente como el resto de las semillas pesadas: `make up`
            // repetido no duplica la serie, y duplicarla ademas chocaria con
            // `employment_contracts_no_overlap`.
            return;
        }

        /** @var list<object{id: int}> $employees */
        $employees = DB::table('employees')->select('id')->orderBy('id')->get()->all();

        $start = now()->subDays(self::HISTORY_DAYS)->toDateString();
        $changeOn = now()->subDays(intdiv(self::HISTORY_DAYS, 2))->toDateString();
        $dayBefore = now()->subDays(intdiv(self::HISTORY_DAYS, 2) + 1)->toDateString();
        $createdAt = (string) now();

        $rows = [];

        foreach ($employees as $index => $employee) {
            if ($index % self::WITHOUT_CONTRACT_EVERY === 0) {
                continue;
            }

            $hours = self::WEEKLY_HOURS[$index % \count(self::WEEKLY_HOURS)];
            $schedule = self::SCHEDULE_TYPES[$index % \count(self::SCHEDULE_TYPES)];

            if ($index % self::CHANGE_EVERY === 0) {
                // El anterior, ya cerrado el dia de antes del nuevo: la serie
                // queda sin hueco y sin solape, exactamente como la deja
                // `RegisterEmploymentContract`.
                $rows[] = $this->row($employee->id, $hours / 2, $schedule, $start, $dayBefore, $createdAt);
                $rows[] = $this->row($employee->id, $hours, $schedule, $changeOn, null, $createdAt);

                continue;
            }

            $rows[] = $this->row($employee->id, $hours, $schedule, $start, null, $createdAt);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('employment_contracts')->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $employeeId,
        float $weeklyHours,
        string $scheduleType,
        string $validFrom,
        ?string $validTo,
        string $createdAt,
    ): array {
        return [
            'employee_id' => $employeeId,
            'weekly_hours' => $weeklyHours,
            // El computo anual del convenio de hosteleria, aproximado desde las
            // semanales. No entra en ningun calculo (ver el modelo de dominio):
            // esta para que la ficha no salga vacia.
            'annual_hours' => round($weeklyHours * 44.5, 2),
            'schedule_type' => $scheduleType,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'created_at' => $createdAt,
            // Sin autor: una semilla no la firma nadie, y forzar uno obligaria a
            // inventar una cuenta de sistema en `users`.
            'created_by_user_id' => null,
        ];
    }
}
