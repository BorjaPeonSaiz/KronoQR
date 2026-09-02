<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * LO QUE ESTE FICHERO PROTEGE, y por que no lo puede proteger ninguna otra
 * prueba de la importacion (RF-GP-05, tarea 5.5).
 *
 * El hash de un PIN cuesta unos 160 ms con el coste 12 de produccion. Es
 * deliberado —encarece el ataque por fuerza bruta contra `pin_hash`— y no se
 * toca. Pero convierte 500 altas en 80 segundos de calculo, y hasta la revision
 * de la 5.5 ese calculo ocurria DENTRO de la transaccion del lote, con dos
 * consecuencias que nadie habria relacionado con una importacion:
 *
 *   1. **El hotel deja de fichar.** El primer asiento del lote toma el
 *      `pg_advisory_xact_lock` global de `audit_log` y no lo suelta hasta el
 *      commit (ADR-010). Cada escaneo del quiosco se serializa detras: una
 *      importacion a media mañana dejaba la tablet de la entrada esperando
 *      minuto y medio.
 *   2. **La peticion moria.** `max_execution_time` son 60 s y el corte llegaba
 *      DESPUES de que quien importa hubiera confirmado, sin saber si habia
 *      entrado alguien.
 *
 * NINGUNA PRUEBA DE LA SUITE PUEDE VERLO POR EL TIEMPO: `phpunit.xml` fija
 * `BCRYPT_ROUNDS=4` —0,7 ms por hash— para que las pruebas no tarden horas, y
 * subirlo aqui solo cambiaria la duracion, no el diagnostico. Lo que se afirma
 * es la propiedad ESTRUCTURAL de la que depende todo lo anterior: **cuando se
 * calcula un hash, la transaccion del lote todavia no esta abierta**. Eso si
 * vuelve a romperse el dia que alguien mueva el calculo de sitio.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un `PinHasher` que ademas anota a que profundidad de transaccion se le llamo.
 *
 * @return PinHasher&object{levels: list<int>}
 */
function pinHasherRecordingTransactionDepth(): PinHasher
{
    return new class implements PinHasher
    {
        /** @var list<int> */
        public array $levels = [];

        public function hash(#[SensitiveParameter] string $pin): PinMaterial
        {
            $this->levels[] = DB::transactionLevel();

            return new PinMaterial($pin, Hash::make($pin));
        }
    };
}

it('calcula los hashes de PIN antes de abrir la transaccion del lote', function (): void {
    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $csv = "nombre,apellidos,dni,fecha_alta\n"
        ."Youssef,Amrani,12345678Z,2026-01-15\n"
        ."Marta,Vidal,87654321X,2026-02-01\n"
        ."Laura,Sanz,11223344B,2026-02-01\n";

    // La profundidad de referencia: `RefreshDatabase` mantiene una transaccion
    // abierta durante toda la prueba, asi que «fuera de la transaccion del lote»
    // no es cero, es esta.
    $baseline = DB::transactionLevel();

    $hasher = pinHasherRecordingTransactionDepth();
    app()->instance(PinHasher::class, $hasher);

    $validated = Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'validate'],
        ['file' => ImportFiles::csv($csv)],
    )->assertValidResponse(200);

    // La simulacion no emite ningun PIN: no escribe una sola fila.
    expect($hasher->levels)->toBe([]);

    $checksum = $validated->json('file.sha256');

    Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'apply', 'confirm_checksum' => \is_string($checksum) ? $checksum : ''],
        ['file' => ImportFiles::csv($csv)],
    )->assertValidResponse(200)->assertJsonPath('summary.create', 3);

    // Un hash por alta, y los tres calculados ANTES de que se abriera la
    // transaccion del lote. Con el calculo dentro, estos valores serian
    // `$baseline + 1` y el candado global de `audit_log` estaria tomado durante
    // los 160 ms de cada uno.
    expect($hasher->levels)->toBe([$baseline, $baseline, $baseline]);
})->group('RF-GP-05', 'RF-ID-09');

it('emite el PIN dentro de la transaccion aunque lo calcule fuera', function (): void {
    // La otra mitad, y la que impide que la correccion se pague con una garantia:
    // sacar el CALCULO no puede sacar la ESCRITURA. Un empleado sin PIN es una
    // persona que no puede fichar por respaldo (RF-AT-11) ni entrar al portal
    // (RL-05), asi que el alta y su PIN siguen siendo un solo hecho.
    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $csv = "nombre,apellidos,dni,fecha_alta\n"
        ."Youssef,Amrani,12345678Z,2026-01-15\n";

    $validated = Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'validate'],
        ['file' => ImportFiles::csv($csv)],
    )->assertValidResponse(200);

    $checksum = $validated->json('file.sha256');

    Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'apply', 'confirm_checksum' => \is_string($checksum) ? $checksum : ''],
        ['file' => ImportFiles::csv($csv)],
    )->assertValidResponse(200);

    // Ninguna fila de `employees` sin `pin_hash` ni sin `pin_issued_at`.
    expect(DB::table('employees')->whereNull('pin_hash')->count())->toBe(0)
        ->and(DB::table('employees')->whereNull('pin_issued_at')->count())->toBe(0);
})->group('RF-GP-05', 'RF-ID-09');
