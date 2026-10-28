<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use Tests\Architecture\Support\Repo;

/*
 * `product:doctor` y los scripts de instalacion vigilan las MISMAS guardas del
 * registro horario (ADR-057 §2, RL-04, RS-07).
 *
 * `ServiceInspector` las mira entre actualizaciones y
 * `infra/scripts/lib/work-record-privileges.sh` las mira desde `update.sh` y
 * `doctor.sh`. Son dos listas escritas a mano en dos lenguajes: si una migracion
 * añade un *trigger* y solo se apunta en una, el otro camino deja de detectar
 * que lo borren. Esta prueba lee las variables `readonly` del script y las
 * compara con las constantes de PHP, nombres de migracion incluidos.
 */

/**
 * El valor de una variable `readonly NOMBRE="…"` del script, sin comillas.
 */
function paridadGuardasVariable(string $name): string
{
    $script = Repo::contents('infra/scripts/lib/work-record-privileges.sh');

    $found = preg_match('/^readonly '.preg_quote($name, '/').'="([^"]*)"$/m', $script, $match);

    expect($found)->toBe(1, 'No se encuentra '.$name.' en work-record-privileges.sh');

    return trim($match[1] ?? '', "'");
}

/**
 * Los nombres de una lista `tabla:nombre tabla:nombre` del script, ordenados.
 *
 * @return list<string>
 */
function paridadGuardasTriggers(string $name): array
{
    $names = array_map(
        static fn (string $entry): string => substr($entry, (int) strpos($entry, ':') + 1),
        preg_split('/\s+/', paridadGuardasVariable($name), -1, PREG_SPLIT_NO_EMPTY) ?: [],
    );
    sort($names);

    return $names;
}

/**
 * @param  list<string>  $names
 * @return list<string>
 */
function paridadGuardasOrdenados(array $names): array
{
    sort($names);

    return $names;
}

it('los triggers del registro son los mismos en el doctor y en el script', function (): void {
    expect(paridadGuardasTriggers('KQ_WORK_RECORD_TRIGGERS'))
        ->toBe(paridadGuardasOrdenados(ServiceInspector::WORK_RECORD_GUARD_TRIGGERS))
        ->and(paridadGuardasVariable('KQ_WORK_RECORD_MIGRATION_PURGE'))
        ->toBe(ServiceInspector::WORK_RECORD_GUARD_MIGRATION);
})->group('RF-PD-13', 'RS-07', 'RL-04');

it('los triggers de la duracion y su migracion son los mismos en el doctor y en el script', function (): void {
    expect(paridadGuardasTriggers('KQ_WORK_RECORD_DURATION_TRIGGERS'))
        ->toBe(paridadGuardasOrdenados(ServiceInspector::WORK_RECORD_DURATION_GUARD_TRIGGERS))
        ->and(paridadGuardasVariable('KQ_WORK_RECORD_DURATION_MIGRATION'))
        ->toBe(ServiceInspector::WORK_RECORD_DURATION_GUARD_MIGRATION);

    // Y la migracion existe con ese nombre: un error tipografico en las dos
    // listas a la vez dejaria la guarda sin exigir para siempre.
    expect(Repo::file('backend/database/migrations/'.ServiceInspector::WORK_RECORD_DURATION_GUARD_MIGRATION.'.php'))
        ->toBeFile();
})->group('RF-PD-13', 'RS-07', 'RL-04', 'RN-06');

it('la reapertura de RN-19 vigila la misma migracion y el mismo trigger en el doctor y en el script', function (): void {
    expect(paridadGuardasVariable('KQ_INCIDENT_REOPENING_MIGRATION'))
        ->toBe(ServiceInspector::INCIDENT_REOPENING_MIGRATION)
        ->and(paridadGuardasTriggers('KQ_INCIDENT_REOPENING_TRIGGER'))
        ->toBe([ServiceInspector::INCIDENT_REOPENING_TRIGGER])
        // Y el trigger es uno de los del registro: su ausencia ya la vigila la
        // lista general, esta solo mira que su funcion sea la de la reapertura.
        ->and(ServiceInspector::WORK_RECORD_GUARD_TRIGGERS)->toContain(ServiceInspector::INCIDENT_REOPENING_TRIGGER);

    expect(Repo::file('backend/database/migrations/'.ServiceInspector::INCIDENT_REOPENING_MIGRATION.'.php'))
        ->toBeFile();
})->group('RF-PD-13', 'RS-07', 'RL-04', 'RN-19');

it('la huella de la funcion de la reapertura y su expresion son las mismas en el doctor y en el script', function (): void {
    // Que coincida con la que deja la migracion lo mira, contra la base migrada,
    // WorkRecordPrivilegesDoctorCheckTest.
    expect(paridadGuardasVariable('KQ_INCIDENT_REOPENING_FINGERPRINT'))
        ->toBe(ServiceInspector::INCIDENT_REOPENING_FINGERPRINT)
        ->toMatch('/^[0-9a-f]{32}$/')
        ->and(paridadGuardasVariable('KQ_GUARD_FUNCTION_FINGERPRINT_SQL'))
        ->toBe(ServiceInspector::GUARD_FUNCTION_FINGERPRINT_SQL);

    // Sin barras invertidas: el shell y PHP la leerian distinto.
    expect(ServiceInspector::GUARD_FUNCTION_FINGERPRINT_SQL)->not->toContain('\\');
})->group('RF-PD-13', 'RS-07', 'RL-04', 'RN-19');
