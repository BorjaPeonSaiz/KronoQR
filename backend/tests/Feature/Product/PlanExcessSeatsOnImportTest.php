<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UN SOLO ASIENTO `license.plan_exceeded` POR IMPORTACION** (H-04 de la
 * revision interna de la 3.8; RF-PD-04, RF-GP-05, ADR-010, ADR-028).
 *
 * ## Que se estaba corrigiendo
 *
 * `ApplyEmployeeImport` reutiliza `RegisterEmployeeHandler` —y hace bien: un
 * alta por importacion tiene que ser un alta de primera—, que publica
 * `EmployeeHired` por fila. De ahi colgaba el observador del plan, asi que un
 * hotel con plan de 80 que importara 300 personas escribia **220 asientos casi
 * identicos**, todos bajo el `pg_advisory_xact_lock` global de `audit_log`
 * (ADR-010), que es el mismo candado por el que pasa **cada fichaje del hotel**.
 *
 * No bloqueaba a nadie —los quioscos encolan, regla dura 19— pero era carga
 * evitable en el unico candado que el producto no puede permitirse congestionar.
 * Y ademas `first_crossing` mentia: calculado como «el exceso vale 1», en un
 * lote lo cumplia la fila que resultara ser la primera pasada del tope, no la
 * importacion.
 *
 * ## Lo que esta prueba afirma
 *
 * Que la importacion deja **exactamente un** asiento, con el recuento final, lo
 * contratado y cuantas de las altas del lote quedaron por encima del plan; que
 * el alta de una en una sigue dejando el suyo; y que una importacion que cabe en
 * el plan no deja ninguno.
 *
 * Antes de la correccion, el primer caso escribia N − plan asientos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * Una licencia activada con el tope de personas indicado.
 */
function importSeatsLicense(int $employees): void
{
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue(['max_employees' => $employees, 'max_devices' => 3]),
    ));
}

/**
 * Un fichero de plantilla con `$rows` personas ficticias (regla dura 13).
 */
function importSeatsCsv(int $rows): string
{
    $lines = [];

    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = ['Persona'.$i, 'De Temporada', sprintf('DOC-%05d', $i), '2026-06-01'];
    }

    return ImportFiles::rows(['nombre', 'apellidos', 'dni', 'fecha_alta'], $lines);
}

/**
 * Valida y aplica, que es lo que hace el panel: la segunda llamada confirma con
 * la huella que devolvio la primera.
 *
 * @return TestResponse<Response>
 */
function importSeatsApply(string $csv): TestResponse
{
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $validated = Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'validate'],
        ['file' => ImportFiles::csv($csv)],
    );

    $checksum = $validated->json('file.sha256');

    return Api::as($token)->upload(
        '/api/v1/employees/import',
        ['mode' => 'apply', 'confirm_checksum' => \is_string($checksum) ? $checksum : ''],
        ['file' => ImportFiles::csv($csv)],
    );
}

/**
 * Los asientos de exceso de plan, ya decodificados y en orden.
 *
 * @return list<array<string, mixed>>
 */
function importSeatsPayloads(): array
{
    /** @var list<object{payload: string}> $rows */
    $rows = DB::table('audit_log')->where('action', 'license.plan_exceeded')->orderBy('id')->get()->all();

    return array_map(
        /** @return array<string, mixed> */
        static fn (object $row): array => (array) json_decode((string) $row->payload, true),
        $rows,
    );
}

it('escribe UN SOLO asiento de exceso al importar una plantilla entera por encima del plan', function (): void {
    importSeatsLicense(employees: 3);

    importSeatsApply(importSeatsCsv(10))
        ->assertSuccessful()
        ->assertJsonPath('summary.create', 10);

    // Las diez entraron: el plan no bloquea nada (ADR-028, regla dura 15).
    expect(DB::table('employees')->where('status', 'active')->count())->toBe(10);

    $payloads = importSeatsPayloads();

    // ESTA ES LA AFIRMACION DE H-04. Antes de la correccion eran siete.
    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['limit'])->toBe('max_employees')
        ->and($payloads[0]['contracted'])->toBe(3)
        // El recuento FINAL, no el de la fila que cruzo.
        ->and($payloads[0]['reached'])->toBe(10)
        ->and($payloads[0]['excess'])->toBe(7)
        // Cuantas de las altas de ESTE lote quedaron por encima del plan.
        ->and($payloads[0]['added_in_excess'])->toBe(7)
        // La importacion es la que cruzo el umbral, y lo dice una vez.
        ->and($payloads[0]['first_crossing'])->toBeTrue()
        ->and($payloads[0]['operation_blocked'])->toBeFalse();
})->group('RF-PD-04', 'RF-GP-05');

it('cuenta solo las altas del lote que quedaron por encima del plan', function (): void {
    // El plan admite tres y el fichero trae cinco: cruzan dos. Con el asiento por
    // fila esta cifra no existia, y `first_crossing` la insinuaba mal.
    importSeatsLicense(employees: 3);

    importSeatsApply(importSeatsCsv(5))->assertSuccessful();

    $payloads = importSeatsPayloads();

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['reached'])->toBe(5)
        ->and($payloads[0]['added_in_excess'])->toBe(2)
        ->and($payloads[0]['first_crossing'])->toBeTrue();
})->group('RF-PD-04', 'RF-GP-05');

it('no escribe ningun asiento cuando la importacion cabe en el plan', function (): void {
    importSeatsLicense(employees: 50);

    importSeatsApply(importSeatsCsv(10))->assertSuccessful();

    expect(importSeatsPayloads())->toBe([]);
})->group('RF-PD-04', 'RF-GP-05');

it('el alta de una en una sigue dejando su propio asiento despues de la importacion', function (): void {
    // La correccion silencia la evaluacion POR FILA durante el lote, no el
    // camino individual: si lo silenciara, se perderia la evidencia comercial de
    // cada alta en exceso hecha desde el panel (ADR-028).
    importSeatsLicense(employees: 3);

    importSeatsApply(importSeatsCsv(5))->assertSuccessful();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees', [
            'first_name' => 'Camarero',
            'last_name' => 'De Refuerzo',
            'hired_at' => '2026-06-15',
            'national_id' => 'DOC-99999',
        ])->assertSuccessful();

    $payloads = importSeatsPayloads();

    expect($payloads)->toHaveCount(2)
        ->and($payloads[1]['reached'])->toBe(6)
        ->and($payloads[1]['excess'])->toBe(3)
        // Una sola alta, y solo esa esta en exceso.
        ->and($payloads[1]['added_in_excess'])->toBe(1)
        // El umbral ya estaba cruzado por la importacion.
        ->and($payloads[1]['first_crossing'])->toBeFalse();
})->group('RF-PD-04', 'RF-GP-05');
