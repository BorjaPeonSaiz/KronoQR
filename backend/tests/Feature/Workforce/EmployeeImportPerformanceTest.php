<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * LO QUE ESTE FICHERO PROTEGE: que una importacion masiva no congele el
 * quiosco (RF-GP-05, regla dura 19).
 *
 * El hash de un PIN cuesta unos 160 ms con el coste 12 de produccion. Es
 * deliberado —encarece el ataque por fuerza bruta contra `pin_hash`— y no se
 * toca. Pero 500 altas son 80 segundos de calculo, y hasta la revision de la
 * 5.5 ese calculo ocurria DENTRO de la transaccion del lote: el primer asiento
 * toma el `pg_advisory_xact_lock` global de `audit_log` (ADR-010) y cada
 * escaneo del hotel esperaba detras, ademas de que la peticion moria al llegar
 * a `max_execution_time`.
 *
 * Desde el bloque 12b la importacion NO EMITE PIN (RF-ID-09): las altas nacen
 * con el PIN pendiente y RRHH lo emite al entregar la tarjeta. La propiedad que
 * se protege es por tanto mas fuerte que antes: **la importacion no calcula
 * ningun hash de PIN**, ni dentro ni fuera de la transaccion. Que las altas
 * queden pendientes y sin `pin.issued` lo afirma `EmployeeImportTest`.
 *
 * NINGUNA PRUEBA DE LA SUITE PUEDE VERLO POR EL TIEMPO: `phpunit.xml` fija
 * `BCRYPT_ROUNDS=4`. Lo que se afirma es la propiedad estructural: el
 * `PinHasher` no recibe ninguna llamada. Si alguien vuelve a emitir el PIN en la
 * importacion, esta prueba lo ve aunque lo haga fuera de la transaccion.
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

it('la importacion no calcula ningun hash de PIN', function (): void {
    WorkforceFixtures::site();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $csv = "nombre,apellidos,dni,fecha_alta\n"
        ."Youssef,Amrani,12345678Z,2026-01-15\n"
        ."Marta,Vidal,87654321X,2026-02-01\n"
        ."Laura,Sanz,11223344B,2026-02-01\n";

    $hasher = pinHasherRecordingTransactionDepth();
    app()->instance(PinHasher::class, $hasher);

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
    )->assertValidResponse(200)->assertJsonPath('summary.create', 3);

    expect($hasher->levels)->toBe([]);
})->group('RF-GP-05', 'RF-ID-09');

it('el control: el alta individual si pasa por el mismo PinHasher', function (): void {
    // Sin este control, un `PinHasher` que el contenedor no inyectara en ningun
    // sitio haria pasar la prueba de arriba sin demostrar nada.
    WorkforceFixtures::site();

    $hasher = pinHasherRecordingTransactionDepth();
    app()->instance(PinHasher::class, $hasher);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees', [
            'first_name' => 'Youssef',
            'last_name' => 'Amrani',
            'hired_at' => '2026-01-15',
        ])->assertValidResponse(201);

    expect($hasher->levels)->toHaveCount(1);
})->group('RF-GP-01', 'RF-ID-09');
