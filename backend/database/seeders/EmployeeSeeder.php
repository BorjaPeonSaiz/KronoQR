<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * La plantilla de la semilla de desarrollo (RF-GP-01).
 *
 * **Volumen deliberado.** 200 personas por centro, 600 en total, que es el
 * tamano que el doc 02 Anexo A usa para el panel («virtualizacion para 500
 * empleados») y el que hace que una consulta sin indice se note. Una semilla de
 * diez empleados da por bueno cualquier plan de ejecucion.
 *
 * **Ningun dato personal real.** Los nombres se componen de dos listas cortas y
 * el `employee_code` es opaco y aleatorio-pero-reproducible: se deriva de un
 * hash del indice, nunca del nombre ni de un numero secuencial (doc 01 §5.5).
 * Un codigo secuencial impreso en una tarjeta revela cuanta gente hay y en que
 * orden entro.
 *
 * **El DNI no existe ni siquiera aqui.** `national_id_hash` se calcula con
 * `pgcrypto` a partir del propio `employee_code` en una sola sentencia: en
 * ningun momento hay un documento de identidad en la base, en el codigo ni en
 * el registro de sentencias (RL-08).
 *
 * El 8 % de la plantilla nace de baja (`terminated`) para que RN-14 y RF-GP-03
 * tengan sobre que probarse: el empleado de baja conserva su historial y sus
 * escaneos se rechazan.
 */
final class EmployeeSeeder extends Seeder
{
    private const int EMPLOYEES_PER_SITE = 250;

    /** PIN de desarrollo, comun a toda la semilla. Se hashea una sola vez. */
    private const string DEVELOPMENT_PIN = '246813';

    /** @var list<string> */
    private const array FIRST_NAMES = [
        'Lucia', 'Marta', 'Carmen', 'Ainhoa', 'Nerea', 'Paula', 'Sara', 'Elena',
        'Javier', 'Alberto', 'Ruben', 'Sergio', 'Ivan', 'Hugo', 'Marcos', 'Adrian',
    ];

    /** @var list<string> */
    private const array LAST_NAMES = [
        'Ferrer', 'Solano', 'Quiroga', 'Bernal', 'Herrera', 'Vidal', 'Bustos',
        'Palacios', 'Cabrera', 'Montero', 'Nogueira', 'Escudero',
    ];

    public function run(): void
    {
        $pinHash = Hash::make(self::DEVELOPMENT_PIN);
        $now = now();

        /** @var list<object{id: int}> $sites */
        $sites = DB::table('sites')->select('id')->orderBy('id')->get()->all();

        foreach ($sites as $siteIndex => $site) {
            /** @var list<int> $departmentIds */
            $departmentIds = DB::table('departments')
                ->where('site_id', $site->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($departmentIds === []) {
                continue;
            }

            $this->seedSite($siteIndex, $site->id, $departmentIds, $pinHash, (string) $now);
        }

        $this->hashNationalIds();
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function seedSite(int $siteIndex, int $siteId, array $departmentIds, string $pinHash, string $now): void
    {
        $rows = [];

        for ($i = 0; $i < self::EMPLOYEES_PER_SITE; $i++) {
            $ordinal = $siteIndex * self::EMPLOYEES_PER_SITE + $i;
            $terminated = $ordinal % 12 === 5;

            $rows[] = [
                'uuid' => Str::uuid7()->toString(),
                'site_id' => $siteId,
                'department_id' => $departmentIds[$i % \count($departmentIds)],
                'first_name' => self::FIRST_NAMES[$ordinal % \count(self::FIRST_NAMES)],
                'last_name' => self::LAST_NAMES[intdiv($ordinal, 7) % \count(self::LAST_NAMES)],
                'employee_code' => $this->opaqueCode($ordinal),
                'email' => null,
                'pin_hash' => $pinHash,
                // `pin_issued_at` va con el hash y no es opcional: la
                // restriccion `employees_chk_pin_issue_is_complete` de la tarea
                // 1.13 exige que las dos columnas esten o falten a la vez. Un PIN
                // con hash y sin fecha de emision es un estado que el panel de
                // RF-ID-09 no sabe pintar —ni «sin emitir» ni «emitido»— y que
                // ademas dejaba la semilla entera sin poder ejecutarse.
                'pin_issued_at' => $now,
                'photo_path' => null,
                'status' => $terminated ? 'terminated' : 'active',
                'hired_at' => now()->subDays(400 + $ordinal)->toDateString(),
                'terminated_at' => $terminated ? now()->subDays(30)->toDateString() : null,
                'locale' => 'es',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // `insertOrIgnore` y no `insert`: repetir la semilla no debe romper por
        // el UNIQUE de `employee_code`, que es estable a proposito.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('employees')->insertOrIgnore($chunk);
        }
    }

    /**
     * Codigo de empleado **opaco**: ni secuencial ni derivado del nombre, pero
     * reproducible entre ejecuciones de la semilla.
     */
    private function opaqueCode(int $ordinal): string
    {
        return 'E'.mb_strtoupper(mb_substr(hash('sha256', 'kronoqr-seed-employee-'.$ordinal), 0, 9));
    }

    /**
     * RL-08: el hash del documento, nunca el documento.
     *
     * Lo calcula `pgcrypto` en una sola sentencia a partir del `employee_code`,
     * asi que no hay ningun DNI que proteger en la semilla. En produccion el
     * origen es el documento real y lo hashea `Workforce` (tarea 1.6) antes de
     * que llegue a la base de datos.
     */
    private function hashNationalIds(): void
    {
        DB::statement(<<<'SQL'
            UPDATE employees
               SET national_id_hash = digest('kronoqr-seed-document-' || employee_code, 'sha256')
             WHERE national_id_hash IS NULL
        SQL);
    }
}
