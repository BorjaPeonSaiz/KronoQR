<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El esquema de `absences` dice lo mismo que el codigo, y su `down()` existe de
 * verdad (**RF-GP-04**, RNF-D-04, «una migracion cuyo `down()` no se ha probado
 * no tiene `down()`»).
 *
 * ## Por que las invariantes se comprueban contra el motor
 *
 * Porque **la que manda es la del esquema**, no la de PHP. `absences_no_overlap`
 * es lo unico que impide que dos altas simultaneas registren el mismo dia dos
 * veces: una comprobacion previa desde el dominio es una carrera con aspecto de
 * comprobacion, y el numero que sale mal de ahi acaba en un informe de
 * absentismo con consecuencias laborales.
 *
 * Y porque una migracion aplicada a medias, o un `ALTER` hecho a mano en la
 * instalacion de un cliente, no se ven de ninguna otra forma.
 */

uses(RefreshDatabase::class);

/**
 * Una fila valida de `absences`, escrita con el constructor de consultas y sin
 * pasar por el dominio: las pruebas de esquema tienen que poder intentar
 * escribir filas que el dominio no escribiria.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function filaDeAusencia(int $employeeId, array $overrides = []): array
{
    return [
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'type' => 'vacation',
        'starts_on' => '2026-03-02',
        'ends_on' => '2026-03-06',
        'note' => null,
        'status' => 'active',
        'version' => 1,
        'created_at' => '2026-03-01T08:00:00+00:00',
        ...$overrides,
    ];
}

function empleadoConAusencias(): int
{
    $site = WorkforceFixtures::site('Hotel de ausencias');
    $uuid = WorkforceFixtures::employee($site);

    /** @var int|string|null $id */
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');

    return \is_numeric($id) ? (int) $id : 0;
}

it('rechaza dos ausencias activas solapadas de la misma persona', function (): void {
    // La invariante central de RF-GP-04: con dos ausencias solapadas, el mismo
    // dia se contaria dos veces y el absentismo justificado de un departamento
    // dejaria de cuadrar con el numero de personas que hay en el.
    $employeeId = empleadoConAusencias();

    DB::table('absences')->insert(filaDeAusencia($employeeId));

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        // Empieza el ultimo dia de la anterior: los dos extremos son inclusivos.
        'starts_on' => '2026-03-06',
        'ends_on' => '2026-03-10',
    ])))->toThrow(QueryException::class);
})->group('RF-GP-04');

it('admite dos ausencias consecutivas que no comparten ningun dia', function (): void {
    // El otro lado del limite: si la restriccion fuera demasiado estricta,
    // registrar vacaciones justo despues de una baja seria imposible.
    $employeeId = empleadoConAusencias();

    DB::table('absences')->insert(filaDeAusencia($employeeId));

    DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'starts_on' => '2026-03-07',
        'ends_on' => '2026-03-10',
    ]));

    expect(DB::table('absences')->count())->toBe(2);
})->group('RF-GP-04');

it('deja registrar sobre los dias de una ausencia supersedida o anulada', function (): void {
    // El `WHERE (status = 'active')` de la restriccion es lo que hace compatible
    // la invariante con la regla dura 5: las versiones anteriores cubren los
    // mismos dias y se quedan en la tabla para siempre.
    $employeeId = empleadoConAusencias();

    $anulada = filaDeAusencia($employeeId, [
        'status' => 'voided',
        'voided_at' => '2026-03-08T10:00:00+00:00',
        'void_reason' => 'Se registro a la persona equivocada.',
    ]);

    DB::table('absences')->insert($anulada);
    DB::table('absences')->insert(filaDeAusencia($employeeId));

    expect(DB::table('absences')->count())->toBe(2);
})->group('RF-GP-04', 'RN-13');

it('no admite un catalogo de tipos ni de estados distinto del del codigo', function (): void {
    // Las dos copias —el `CHECK` y el enum— las ata esta prueba, no la buena fe.
    $employeeId = empleadoConAusencias();

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, ['type' => 'excedencia'])))
        ->toThrow(QueryException::class);

    expect(AbsenceStatus::names())->toBe(['active', 'superseded', 'voided']);
})->group('RF-GP-04');

it('admite exactamente los cuatro tipos que declara el enum', function (): void {
    /*
     * La otra mitad de la prueba anterior, y en un caso aparte **por una razon
     * tecnica que conviene dejar escrita**: `RefreshDatabase` envuelve cada
     * prueba en una transaccion, y en PostgreSQL una sentencia que falla la
     * aborta entera —`SQLSTATE 25P02`—. Comprobar rechazos y aceptaciones en el
     * mismo caso hace que las segundas fallen por el rechazo anterior y no por
     * lo que se queria comprobar.
     *
     * Se insertan de verdad, cada uno en su propio mes para no chocar con la
     * restriccion de exclusion, en lugar de leer la definicion del `CHECK` con
     * una expresion regular: lo que importa no es como esta escrita, es que
     * admita justo estos cuatro. Las dos copias del catalogo —el `CHECK` de la
     * migracion y el enum— las ata esta prueba, no la buena fe.
     */
    $employeeId = empleadoConAusencias();
    $mes = 0;

    foreach (AbsenceType::names() as $type) {
        $mes++;

        DB::table('absences')->insert(filaDeAusencia($employeeId, [
            'type' => $type,
            'starts_on' => '2027-0'.$mes.'-01',
            'ends_on' => '2027-0'.$mes.'-02',
        ]));
    }

    expect(DB::table('absences')->count())->toBe(4);
})->group('RF-GP-04');

it('no admite un periodo invertido', function (): void {
    $employeeId = empleadoConAusencias();

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'starts_on' => '2026-03-10',
        'ends_on' => '2026-03-01',
    ])))->toThrow(QueryException::class);
})->group('RF-GP-04');

it('exige motivo de cambio a partir de la version 2, y solo a partir de ella', function (): void {
    // Sin esto, una correccion sin motivo seria indistinguible de un alta, que es
    // justo lo que RN-13 exige poder distinguir.
    $employeeId = empleadoConAusencias();

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'version' => 2,
        'change_reason' => null,
    ])))->toThrow(QueryException::class);

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'version' => 1,
        'change_reason' => 'No deberia poder tener motivo.',
    ])))->toThrow(QueryException::class);
})->group('RF-GP-04', 'RN-13');

it('no admite estados a medias de anulacion ni de sustitucion', function (): void {
    // Una fila `voided` sin momento no dice cuando dejo de valer; una con momento
    // que sigue `active` cuenta en el informe despues de haberse anulado. Las dos
    // son igual de malas.
    $employeeId = empleadoConAusencias();

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, ['status' => 'voided'])))
        ->toThrow(QueryException::class);

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'voided_at' => '2026-03-08T10:00:00+00:00',
        'void_reason' => 'Motivo.',
    ])))->toThrow(QueryException::class);

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, ['status' => 'superseded'])))
        ->toThrow(QueryException::class);
})->group('RF-GP-04', 'RN-13');

it('acota la nota y los dos motivos a 500 caracteres', function (): void {
    // El esquema protege de lo que no pasa por la API: una carga por fichero, un
    // `psql` a las tres de la mañana.
    $employeeId = empleadoConAusencias();

    expect(static fn () => DB::table('absences')->insert(filaDeAusencia($employeeId, [
        'note' => str_repeat('a', 501),
    ])))->toThrow(QueryException::class);
})->group('RF-GP-04');

it('no borra una ficha con ausencias registradas', function (): void {
    // `RESTRICT` y no cascada (regla dura 5, RN-14): dar de baja a alguien no
    // borra su ficha, y el informe de un periodo pasado sigue necesitando sus
    // ausencias.
    $employeeId = empleadoConAusencias();

    DB::table('absences')->insert(filaDeAusencia($employeeId));

    expect(static fn () => DB::table('employees')->where('id', $employeeId)->delete())
        ->toThrow(QueryException::class);
})->group('RF-GP-04', 'RN-14');

it('la migracion se deshace y se reaplica dejando la tabla igual', function (): void {
    // «Una migracion cuyo `down()` no se ha probado no tiene `down()`».
    $migracion = require database_path('migrations/2026_09_22_100000_absences.php');

    $antes = Schema::connection('pgsql_migrator')->getColumnListing('absences');

    expect($antes)->not->toBeEmpty();

    DB::setDefaultConnection('pgsql_migrator');

    try {
        $migracion->down();

        expect(Schema::connection('pgsql_migrator')->hasTable('absences'))->toBeFalse();

        $migracion->up();
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    expect(Schema::connection('pgsql_migrator')->getColumnListing('absences'))->toBe($antes);

    // Y la restriccion de exclusion vuelve: un `down()` que dejara la tabla sin
    // ella daria una instalacion que parece correcta y admite dias duplicados.
    $definicion = DB::select(
        'SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conname = ?',
        ['absences_no_overlap'],
    );

    expect($definicion)->not->toBeEmpty();
})->group('RF-GP-04');
