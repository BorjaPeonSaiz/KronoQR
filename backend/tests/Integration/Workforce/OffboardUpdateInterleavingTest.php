<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\UseCase\ApplyEmployeeImport;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use App\Modules\Workforce\Application\UseCase\UpdateEmployeeHandler;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeeWriteInOtherSession;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\InterleavingEmployeeRepository;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UNA BAJA NO SE DESHACE PORQUE OTRA ESCRITURA LEYO LA FICHA ANTES**
 * (RN-14, RL-04, ADR-046; hallazgos R7-RV-01 y R4-BE-01).
 *
 * `UpdateEmployeeHandler` y `OffboardEmployeeHandler` leian la ficha sin candado
 * y `save()` reescribia la fila entera. Si la baja confirmaba entre la lectura y
 * la escritura de una modificacion, la persona volvia a quedar `active`, sin
 * tarjeta, y con un `employee.offboarded` en `audit_log` que la base
 * contradecia. Por HTTP se reprodujo 15 veces de 30.
 *
 * Aqui no se apuesta por el planificador: {@see InterleavingEmployeeRepository}
 * detiene la primera escritura justo despues de leer la ficha y, en ese hueco,
 * {@see EmployeeWriteInOtherSession} ejecuta la otra escritura en otra sesion
 * con `lock_timeout`. Con la ficha bajo candado (ADR-046) la otra sesion espera
 * y se rinde; sin el, confirma. Despues se repite la otra escritura sin
 * interferencia y se comprueba el estado final.
 *
 * La invariante se lee de la base, no de lo que devuelven los casos de uso:
 * ningun `employee.offboarded` de una persona que no este `terminated`, ninguna
 * persona `terminated` con una tarjeta activa, y ningun `employee.updated` que
 * diga que cambio un apellido que la base no tiene.
 *
 * **`CommittedDatabase` y no `RefreshDatabase`**: la otra sesion no veria a la
 * persona dentro de la transaccion envolvente de la prueba.
 */

uses(CommittedDatabase::class);

const OFFBOARD_UPDATE_INTERLEAVING_NOW = '2026-10-02 10:00:00';

const OFFBOARD_UPDATE_INTERLEAVING_LAST_DAY = '2026-10-02';

const OFFBOARD_UPDATE_INTERLEAVING_EMAIL = 'persona.intercalada@example.test';

beforeEach(function (): void {
    FrozenTime::at(OFFBOARD_UPDATE_INTERLEAVING_NOW);
});

/**
 * Una persona activa, con correo y con una tarjeta impresa y sin revocar.
 */
function personaActivaConTarjeta(): string
{
    $site = WorkforceFixtures::site('Hotel del intercalado');
    $uuid = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'), lastName: 'Apellido Original');

    DB::table('employees')->where('uuid', $uuid)->update(['email' => OFFBOARD_UPDATE_INTERLEAVING_EMAIL]);
    Credentials::issueFor(idDeLaFichaIntercalada($uuid));

    return $uuid;
}

function idDeLaFichaIntercalada(string $uuid): int
{
    /** @var int $id */
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');

    return $id;
}

/**
 * A partir de aqui, la primera lectura de esa ficha deja pasar a la otra sesion.
 */
function laOtraSesionEscribeTrasLeer(string $uuid, EmployeeWriteInOtherSession $otraSesion): void
{
    app()->instance(EmployeeRepository::class, new InterleavingEmployeeRepository(
        app(EmployeeRepository::class),
        $uuid,
        $otraSesion->run(...),
    ));
}

function modificarApellidosIntercalado(string $uuid, string $apellidos): void
{
    app(UpdateEmployeeHandler::class)->handle(new UpdateEmployeeCommand(uuid: $uuid, lastName: $apellidos));
}

function darDeBajaIntercalado(string $uuid): void
{
    app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand(
        uuid: $uuid,
        terminatedAt: OFFBOARD_UPDATE_INTERLEAVING_LAST_DAY,
        reason: 'Fin de contrato de temporada',
    ));
}

/**
 * @return array<string, list<string>>
 */
function aliasesDeLaImportacionIntercalada(): array
{
    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');

    return $aliases;
}

/**
 * Lo que la base dice que es la ficha, para compararlo de una vez.
 *
 * @return array{status: string, terminated_at: string|null, last_name: string, active_cards: int}
 */
function fichaEnLaBase(string $uuid): array
{
    /** @var object{id: int, status: string, terminated_at: string|null, last_name: string} $row */
    $row = DB::table('employees')->where('uuid', $uuid)->first(['id', 'status', 'terminated_at', 'last_name']);

    return [
        'status' => $row->status,
        'terminated_at' => $row->terminated_at,
        'last_name' => $row->last_name,
        'active_cards' => DB::table('credentials')->where('employee_id', $row->id)->whereNull('revoked_at')->count(),
    ];
}

function asientosDeLaFichaIntercalada(string $action, string $uuid): int
{
    return DB::table('audit_log')->where('action', $action)->where('payload->employee_uuid', $uuid)->count();
}

/**
 * Las contradicciones entre `audit_log` y la ficha (ADR-046 §5). Vacia es
 * coherente; cada elemento describe una contradiccion.
 *
 * @return list<string>
 */
function contradiccionesDeLaFicha(string $uuid, string $apellidosPedidos): array
{
    /** @var list<object{contradiccion: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT 'employee.offboarded con la persona ' || e.status
               || ' y terminated_at ' || COALESCE(e.terminated_at::text, 'NULL') AS contradiccion
          FROM audit_log a
          JOIN employees e ON e.uuid::text = a.payload->>'employee_uuid'
         WHERE a.action = 'employee.offboarded'
           AND e.uuid = ?::uuid
           AND (e.status <> 'terminated' OR e.terminated_at IS NULL)
        UNION ALL
        SELECT 'persona terminated con la tarjeta ' || c.uuid::text || ' activa'
          FROM credentials c
          JOIN employees e ON e.id = c.employee_id
         WHERE e.uuid = ?::uuid
           AND e.status = 'terminated'
           AND c.revoked_at IS NULL
        UNION ALL
        SELECT 'employee.updated cambia last_name y la base conserva «' || e.last_name || '»'
          FROM audit_log a
          JOIN employees e ON e.uuid::text = a.payload->>'employee_uuid'
         WHERE a.action = 'employee.updated'
           AND e.uuid = ?::uuid
           AND a.payload->'changed_fields' @> '["last_name"]'::jsonb
           AND e.last_name <> ?
    SQL, [$uuid, $uuid, $uuid, $apellidosPedidos]);

    return array_map(static fn (object $row): string => $row->contradiccion, $rows);
}

it('una modificacion que leyo la ficha antes de la baja no reactiva a la persona', function (): void {
    $persona = personaActivaConTarjeta();
    $baja = EmployeeWriteInOtherSession::offboard($persona, OFFBOARD_UPDATE_INTERLEAVING_LAST_DAY, OFFBOARD_UPDATE_INTERLEAVING_NOW);
    laOtraSesionEscribeTrasLeer($persona, $baja);

    modificarApellidosIntercalado($persona, 'Apellido Nuevo');

    expect(contradiccionesDeLaFicha($persona, 'Apellido Nuevo'))->toBe([])
        ->and($baja->outcome())->toBe(EmployeeWriteInOtherSession::LOCK_TIMEOUT);

    darDeBajaIntercalado($persona);

    expect(fichaEnLaBase($persona))->toBe([
        'status' => 'terminated',
        'terminated_at' => '2026-10-02',
        'last_name' => 'Apellido Nuevo',
        'active_cards' => 0,
    ])
        ->and(asientosDeLaFichaIntercalada('employee.offboarded', $persona))->toBe(1)
        ->and(contradiccionesDeLaFicha($persona, 'Apellido Nuevo'))->toBe([]);
})->group('RF-GP-01', 'RF-GP-03', 'RN-14', 'RL-04');

it('una baja que leyo la ficha antes de una modificacion no la pisa ni deja un asiento que la base contradiga', function (): void {
    $persona = personaActivaConTarjeta();
    $modificacion = EmployeeWriteInOtherSession::updateLastName($persona, 'Apellido Nuevo', OFFBOARD_UPDATE_INTERLEAVING_NOW);
    laOtraSesionEscribeTrasLeer($persona, $modificacion);

    darDeBajaIntercalado($persona);

    expect(contradiccionesDeLaFicha($persona, 'Apellido Nuevo'))->toBe([])
        ->and($modificacion->outcome())->toBe(EmployeeWriteInOtherSession::LOCK_TIMEOUT);

    // Repetida sin interferencia, la modificacion llega a una persona ya de baja.
    expect(fn () => modificarApellidosIntercalado($persona, 'Apellido Nuevo'))
        ->toThrow(EmployeeAlreadyTerminated::class);

    expect(fichaEnLaBase($persona))->toBe([
        'status' => 'terminated',
        'terminated_at' => '2026-10-02',
        'last_name' => 'Apellido Original',
        'active_cards' => 0,
    ])
        ->and(asientosDeLaFichaIntercalada('employee.updated', $persona))->toBe(0)
        ->and(asientosDeLaFichaIntercalada('employee.offboarded', $persona))->toBe(1);
})->group('RF-GP-01', 'RF-GP-03', 'RN-14', 'RL-04');

it('una importacion que leyo la ficha antes de la baja no reactiva a la persona', function (): void {
    $persona = personaActivaConTarjeta();
    $fichero = ImportFiles::csv(ImportFiles::rows(
        ['nombre', 'apellidos', 'email', 'fecha_alta'],
        [['Persona', 'Apellido Nuevo', OFFBOARD_UPDATE_INTERLEAVING_EMAIL, '2026-01-01']],
    ));
    // La comprobacion, antes de interceptar nada: lee la ficha con el mismo puerto.
    $informe = app(PlanEmployeeImport::class)->handle(
        (string) $fichero->getRealPath(),
        500,
        aliasesDeLaImportacionIntercalada(),
    );
    $baja = EmployeeWriteInOtherSession::offboard($persona, OFFBOARD_UPDATE_INTERLEAVING_LAST_DAY, OFFBOARD_UPDATE_INTERLEAVING_NOW);
    laOtraSesionEscribeTrasLeer($persona, $baja);

    app(ApplyEmployeeImport::class)->handle($informe);

    expect(contradiccionesDeLaFicha($persona, 'Apellido Nuevo'))->toBe([])
        ->and($baja->outcome())->toBe(EmployeeWriteInOtherSession::LOCK_TIMEOUT);

    darDeBajaIntercalado($persona);

    expect(fichaEnLaBase($persona))->toBe([
        'status' => 'terminated',
        'terminated_at' => '2026-10-02',
        'last_name' => 'Apellido Nuevo',
        'active_cards' => 0,
    ])
        ->and(asientosDeLaFichaIntercalada('employee.offboarded', $persona))->toBe(1)
        ->and(contradiccionesDeLaFicha($persona, 'Apellido Nuevo'))->toBe([]);
})->group('RF-GP-05', 'RF-GP-03', 'RN-14', 'RL-04');
