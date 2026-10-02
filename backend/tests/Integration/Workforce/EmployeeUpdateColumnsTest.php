<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Command\RecordPinDeliveryCommand;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\RecordPinDeliveryHandler;
use App\Modules\Workforce\Application\UseCase\ResetEmployeePinHandler;
use App\Modules\Workforce\Application\UseCase\UpdateEmployeeHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **NINGUNA ESCRITURA DE LA FICHA TOCA SU CLAVE** (ADR-046 §1.1 punto 4 y §3;
 * condicion A-5 del dictamen de seguridad).
 *
 * `id`, `uuid` y `employee_code` tienen indice unico completo. Un `UPDATE` que
 * los escriba —aunque sea con el mismo valor— toma `FOR UPDATE` en lugar de
 * `FOR NO KEY UPDATE`, choca con el `FOR KEY SHARE` de cada fichaje, ausencia y
 * tarjeta de esa persona, y reabre el ciclo de candados.
 *
 * Se mira el **SQL que de verdad se ejecuta** (`DB::listen`), no el fuente: un
 * `->save()` de Eloquent, un `fill()` o un cambio en el constructor de
 * consultas escribirian la clave sin que ningun `grep` lo viera. Cubre a todos
 * los escritores de una ficha que ya existe: la modificacion (con cambio de
 * estado y de departamento), la baja, el restablecimiento y la entrega del PIN,
 * y el rehash oportunista del PIN al entrar en el portal.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
});

/**
 * Ejecuta la operacion y devuelve, por cada `UPDATE` de `employees` que se
 * ejecuto, las columnas de su `SET`.
 *
 * @return list<list<string>>
 */
function columnasQueEscribeEnLaFicha(callable $operacion): array
{
    $sets = [];

    DB::listen(static function (QueryExecuted $query) use (&$sets): void {
        if (preg_match('/^\s*update\s+"?employees"?\s+set\s+(.+?)\s+where\b/is', $query->sql, $match) === 1) {
            preg_match_all('/"?([a-z_]+)"?\s*=/i', $match[1], $columns);
            $sets[] = $columns[1];
        }
    });

    $operacion();

    return $sets;
}

/**
 * Lo que cada escritor de la ficha necesita para escribir de verdad.
 */
function escritorDeLaFicha(string $escritor): callable
{
    $site = WorkforceFixtures::site();
    $persona = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'));
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    EmployeePins::issue($persona, PortalLogins::PIN);

    return match ($escritor) {
        'modificacion' => static fn (): mixed => app(UpdateEmployeeHandler::class)->handle(new UpdateEmployeeCommand(
            uuid: $persona,
            lastName: 'Apellido Nuevo',
            departmentId: $cocina,
            departmentGiven: true,
            status: 'suspended',
        )),
        'baja' => static fn (): mixed => app(OffboardEmployeeHandler::class)->handle(
            new OffboardEmployeeCommand(uuid: $persona, terminatedAt: '2026-10-02'),
        ),
        'restablecimiento del PIN' => static fn (): mixed => app(ResetEmployeePinHandler::class)->handle(
            new ResetEmployeePinCommand($persona),
        ),
        'entrega del PIN' => static fn (): mixed => app(RecordPinDeliveryHandler::class)->handle(new RecordPinDeliveryCommand(
            employeeUuid: $persona,
            deliveredByUserUuid: ManagementUsers::withRole(UserRole::RRHH)->uuid,
        )),
        'rehash del PIN al entrar al portal' => accesoAlPortalConUnHashViejo($persona),
        default => throw new RuntimeException('Escritor desconocido: '.$escritor),
    };
}

/**
 * Deja el PIN con un hash de otro coste —fuera de la operacion que se observa— y
 * devuelve el acceso al portal, que lo reescribe con el coste actual.
 */
function accesoAlPortalConUnHashViejo(string $persona): callable
{
    DB::table('employees')->where('uuid', $persona)->update([
        'pin_hash' => password_hash(PortalLogins::PIN, PASSWORD_BCRYPT, ['cost' => 5]),
    ]);

    return static fn (): string => PortalLogins::tokenFor(EmployeePins::codeOf($persona));
}

it('escribe la ficha sin escribir id, uuid ni employee_code', function (string $escritor): void {
    $operacion = escritorDeLaFicha($escritor);

    $sets = columnasQueEscribeEnLaFicha($operacion);
    $columnas = array_values(array_unique(array_merge(...$sets)));

    expect($sets)->not->toBe([])
        ->and(array_values(array_intersect($columnas, ['id', 'uuid', 'employee_code'])))->toBe([]);
})->with([
    'modificacion' => ['modificacion'],
    'baja' => ['baja'],
    'restablecimiento del PIN' => ['restablecimiento del PIN'],
    'entrega del PIN' => ['entrega del PIN'],
    'rehash del PIN al entrar al portal' => ['rehash del PIN al entrar al portal'],
])->group('RN-14', 'RF-GP-01', 'RF-GP-03', 'RF-ID-09');

it('el control: la sonda ve una escritura que toca la clave', function (): void {
    // Demuestra que la prueba de arriba puede fallar.
    $persona = WorkforceFixtures::employee(WorkforceFixtures::site());

    $sets = columnasQueEscribeEnLaFicha(static fn (): int => DB::table('employees')
        ->where('uuid', $persona)
        ->update(['employee_code' => 'EZZZZZZZZ', 'last_name' => 'Otro']));

    expect($sets)->toBe([['employee_code', 'last_name']]);
})->group('RN-14');
