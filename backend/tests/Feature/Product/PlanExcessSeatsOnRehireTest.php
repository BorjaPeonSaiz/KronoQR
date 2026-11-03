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
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA READMISION OCUPA PLAZA DEL PLAN, Y EL LOTE CUENTA UNA VEZ** (RN-27,
 * RF-PD-04, ADR-028, ADR-062 §6).
 *
 * La readmision devuelve a la plantilla a alguien que no contaba: ocupa plaza
 * como un alta. La individual deja su asiento `license.plan_exceeded`, como el
 * alta de una en una; la de un lote y la de una importacion dejan **uno solo**
 * por operacion, con el recuento final, igual que la importacion de altas
 * (`PlanExcessSeatsOnImportTest`).
 *
 * Si una regresion volviera a contar por persona, la vuelta de la temporada de
 * 300 personas escribiria 300 asientos casi identicos bajo el candado global de
 * la cadena de `audit_log` (ADR-010), el mismo por el que pasa cada fichaje del
 * hotel, y ninguna otra prueba lo veria.
 *
 * En ningun caso el plan bloquea: todas las readmisiones entran (regla dura 15,
 * ADR-028).
 *
 * Las fichas de baja cesaron el 30 de junio de 2026 (`WorkforceFixtures`) y
 * vuelven el 1 de septiembre.
 */

uses(RefreshDatabase::class);

const PLAN_EXCESS_REHIRE_RETURN = '2026-09-01';

beforeEach(function (): void {
    FrozenTime::at(PLAN_EXCESS_REHIRE_RETURN.' 08:00:00');
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * Una licencia vigente el dia de la vuelta con el tope de personas indicado.
 */
function licenciaDeReadmisionCon(int $employees): void
{
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue([
            'max_employees' => $employees,
            'max_devices' => 3,
            'valid_from' => '2026-01-01T00:00:00Z',
            'valid_until' => '2026-12-31T23:59:59Z',
        ]),
    ));
}

function tokenDeRrhhParaReadmitir(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * Una persona de baja escrita sin pasar por el caso de uso.
 */
function personaDeBajaEnExceso(): string
{
    return WorkforceFixtures::employee(WorkforceFixtures::onlySiteId(), status: 'terminated');
}

function personaEnAltaEnExceso(): string
{
    return WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());
}

/**
 * Valida y aplica la importacion, como el panel: la segunda llamada confirma con
 * la huella del fichero y la de las readmisiones que devolvio la primera.
 *
 * @return TestResponse<Response>
 */
function aplicarImportacionConReadmisiones(string $token, string $csv): TestResponse
{
    $validated = Api::as($token)->upload('/api/v1/employees/import', ['mode' => 'validate'], ['file' => ImportFiles::csv($csv)]);

    $checksum = $validated->json('file.sha256');
    $digest = $validated->json('rehire_digest');

    $fields = ['mode' => 'apply', 'confirm_checksum' => \is_string($checksum) ? $checksum : ''];

    if (\is_string($digest)) {
        $fields['confirm_rehire_digest'] = $digest;
    }

    return Api::as($token)->upload('/api/v1/employees/import', $fields, ['file' => ImportFiles::csv($csv)]);
}

/**
 * Las personas del fichero ya dadas de alta por importacion, con su documento y
 * su nombre, que es como la importacion las reconoce para readmitirlas; y luego
 * de baja. Se hace **antes** de activar la licencia: sin licencia no hay plan y
 * esta preparacion no deja asientos.
 *
 * @param  list<list<string>>  $rows
 */
function plantillaImportadaYDeBaja(string $token, array $rows): void
{
    aplicarImportacionConReadmisiones($token, ImportFiles::rows(['nombre', 'apellidos', 'dni', 'fecha_alta'], $rows))
        ->assertSuccessful();

    /** @var list<string> $uuids */
    $uuids = DB::table('employees')->orderBy('id')->pluck('uuid')->all();

    foreach ($uuids as $uuid) {
        WorkforceFixtures::terminate($uuid);
    }
}

/**
 * Los asientos de exceso de plan, ya decodificados y en orden.
 *
 * @return list<array<string, mixed>>
 */
function asientosDeExcesoTrasReadmitir(): array
{
    /** @var list<object{payload: string}> $rows */
    $rows = DB::table('audit_log')->where('action', 'license.plan_exceeded')->orderBy('id')->get()->all();

    return array_map(
        /** @return array<string, mixed> */
        static fn (object $row): array => (array) json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
        $rows,
    );
}

function personasEnAltaTrasReadmitir(): int
{
    return DB::table('employees')->where('status', 'active')->count();
}

// --- En lote -------------------------------------------------------------------

it('la readmision en lote de cinco personas por encima del plan escribe un solo asiento con el recuento final', function (): void {
    licenciaDeReadmisionCon(employees: 2);
    $lote = [personaDeBajaEnExceso(), personaDeBajaEnExceso(), personaDeBajaEnExceso(), personaDeBajaEnExceso(), personaDeBajaEnExceso()];

    Api::as(tokenDeRrhhParaReadmitir())
        ->post('/api/v1/employees/rehire', ['employee_uuids' => $lote, 'hired_at' => PLAN_EXCESS_REHIRE_RETURN])
        ->assertOk()
        ->assertJsonCount(5, 'rehired');

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(5)
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'limit' => 'max_employees',
            'contracted' => 2,
            'reached' => 5,
            'excess' => 3,
            'added_in_excess' => 3,
            'first_crossing' => true,
            'operation_blocked' => false,
        ]);
})->group('RN-27', 'RF-PD-04', 'ADR-028');

it('la readmision en lote cuando ya se estaba por encima del plan escribe un solo asiento que no es el cruce y no bloquea', function (): void {
    licenciaDeReadmisionCon(employees: 1);
    personaEnAltaEnExceso();
    personaEnAltaEnExceso();
    $lote = [personaDeBajaEnExceso(), personaDeBajaEnExceso(), personaDeBajaEnExceso()];

    Api::as(tokenDeRrhhParaReadmitir())
        ->post('/api/v1/employees/rehire', ['employee_uuids' => $lote, 'hired_at' => PLAN_EXCESS_REHIRE_RETURN])
        ->assertOk()
        ->assertJsonCount(3, 'rehired');

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(5)
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'contracted' => 1,
            'reached' => 5,
            'excess' => 4,
            'added_in_excess' => 3,
            'first_crossing' => false,
            'operation_blocked' => false,
        ]);
})->group('RN-27', 'RF-PD-04', 'ADR-028');

it('la readmision en lote que cabe en el plan no escribe ningun asiento', function (): void {
    licenciaDeReadmisionCon(employees: 5);
    $lote = [personaDeBajaEnExceso(), personaDeBajaEnExceso(), personaDeBajaEnExceso()];

    Api::as(tokenDeRrhhParaReadmitir())
        ->post('/api/v1/employees/rehire', ['employee_uuids' => $lote, 'hired_at' => PLAN_EXCESS_REHIRE_RETURN])
        ->assertOk();

    expect(asientosDeExcesoTrasReadmitir())->toBe([]);
})->group('RN-27', 'RF-PD-04', 'ADR-028');

// --- Por importacion -----------------------------------------------------------

it('la importacion con cuatro lineas de readmision por encima del plan escribe un solo asiento', function (): void {
    $token = tokenDeRrhhParaReadmitir();
    plantillaImportadaYDeBaja($token, [
        ['Persona1', 'De Temporada', 'DOC-00001', '2026-06-01'],
        ['Persona2', 'De Temporada', 'DOC-00002', '2026-06-01'],
        ['Persona3', 'De Temporada', 'DOC-00003', '2026-06-01'],
        ['Persona4', 'De Temporada', 'DOC-00004', '2026-06-01'],
    ]);
    licenciaDeReadmisionCon(employees: 2);

    aplicarImportacionConReadmisiones($token, ImportFiles::rows(['nombre', 'apellidos', 'dni', 'fecha_alta'], [
        ['Persona1', 'De Temporada', 'DOC-00001', PLAN_EXCESS_REHIRE_RETURN],
        ['Persona2', 'De Temporada', 'DOC-00002', PLAN_EXCESS_REHIRE_RETURN],
        ['Persona3', 'De Temporada', 'DOC-00003', PLAN_EXCESS_REHIRE_RETURN],
        ['Persona4', 'De Temporada', 'DOC-00004', PLAN_EXCESS_REHIRE_RETURN],
    ]))
        ->assertOk()
        ->assertJsonPath('summary.rehire', 4)
        ->assertJsonPath('summary.create', 0);

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(4)
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'limit' => 'max_employees',
            'contracted' => 2,
            'reached' => 4,
            'excess' => 2,
            'added_in_excess' => 2,
            'first_crossing' => true,
            'operation_blocked' => false,
        ]);
})->group('RN-27', 'RF-PD-04', 'RF-GP-05', 'ADR-028');

it('la importacion que readmite y da de alta a la vez cuenta las dos cosas en un solo asiento', function (): void {
    $token = tokenDeRrhhParaReadmitir();
    plantillaImportadaYDeBaja($token, [
        ['Persona1', 'De Temporada', 'DOC-00001', '2026-06-01'],
        ['Persona2', 'De Temporada', 'DOC-00002', '2026-06-01'],
    ]);
    licenciaDeReadmisionCon(employees: 1);

    aplicarImportacionConReadmisiones($token, ImportFiles::rows(['nombre', 'apellidos', 'dni', 'fecha_alta'], [
        ['Persona1', 'De Temporada', 'DOC-00001', PLAN_EXCESS_REHIRE_RETURN],
        ['Persona2', 'De Temporada', 'DOC-00002', PLAN_EXCESS_REHIRE_RETURN],
        ['Persona3', 'De Refuerzo', 'DOC-00003', PLAN_EXCESS_REHIRE_RETURN],
    ]))
        ->assertOk()
        ->assertJsonPath('summary.rehire', 2)
        ->assertJsonPath('summary.create', 1);

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(3)
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'contracted' => 1,
            'reached' => 3,
            'excess' => 2,
            // La operacion añade tres: dos readmitidas y una nueva. Si solo
            // contara la nueva, quedaria una en exceso y no seria el cruce.
            'added_in_excess' => 2,
            'first_crossing' => true,
        ]);
})->group('RN-27', 'RF-PD-04', 'RF-GP-05', 'ADR-028');

// --- De una en una -------------------------------------------------------------

it('la readmision individual por encima del plan escribe su asiento, como un alta', function (): void {
    licenciaDeReadmisionCon(employees: 2);
    personaEnAltaEnExceso();
    personaEnAltaEnExceso();
    $readmitida = personaDeBajaEnExceso();

    Api::as(tokenDeRrhhParaReadmitir())
        ->post('/api/v1/employees/'.$readmitida.'/rehire', ['hired_at' => PLAN_EXCESS_REHIRE_RETURN])
        ->assertOk()
        ->assertJsonPath('employee.status', 'active');

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(3)
        ->and($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'contracted' => 2,
            'reached' => 3,
            'excess' => 1,
            'added_in_excess' => 1,
            'first_crossing' => true,
            'operation_blocked' => false,
        ]);
})->group('RN-27', 'RF-PD-04', 'ADR-028');

it('dos readmisiones individuales por encima del plan escriben un asiento cada una', function (): void {
    licenciaDeReadmisionCon(employees: 1);
    personaEnAltaEnExceso();
    $primera = personaDeBajaEnExceso();
    $segunda = personaDeBajaEnExceso();
    $token = tokenDeRrhhParaReadmitir();

    Api::as($token)->post('/api/v1/employees/'.$primera.'/rehire', ['hired_at' => PLAN_EXCESS_REHIRE_RETURN])->assertOk();
    Api::as($token)->post('/api/v1/employees/'.$segunda.'/rehire', ['hired_at' => PLAN_EXCESS_REHIRE_RETURN])->assertOk();

    $asientos = asientosDeExcesoTrasReadmitir();

    expect(personasEnAltaTrasReadmitir())->toBe(3)
        ->and($asientos)->toHaveCount(2)
        ->and([$asientos[0]['reached'], $asientos[0]['first_crossing']])->toBe([2, true])
        ->and([$asientos[1]['reached'], $asientos[1]['first_crossing']])->toBe([3, false]);
})->group('RN-27', 'RF-PD-04', 'ADR-028');
