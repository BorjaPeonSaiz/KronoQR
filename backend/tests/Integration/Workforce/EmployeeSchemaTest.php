<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;

/*
 * Las garantias de `employees` que sostiene PostgreSQL, probadas **por SQL
 * directo** (doc 01 §5.5, RF-GP-01, RF-GP-03, RL-08).
 *
 * Ninguna prueba de este fichero usa el caso de uso ni el repositorio: se
 * escribe con el constructor de consultas, que es lo mas parecido a un `psql`
 * que la suite puede ejecutar. Lo que se comprueba es lo que ocurre cuando
 * alguien escribe **sin pasar por el dominio** —una importacion, un script, una
 * condicion de carrera—, que es justo donde el codigo de aplicacion no protege.
 */

uses(RefreshDatabase::class);

function siteForSchemaTest(): int
{
    return DB::table('sites')->insertGetId([
        'name' => 'Centro de pruebas '.Str::random(6),
        'timezone' => 'Europe/Madrid',
        'settings' => '{}',
        'created_at' => '2026-01-01 00:00:00+00',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertEmployee(int $siteId, array $overrides = []): string
{
    $uuid = Str::uuid7()->toString();

    DB::table('employees')->insert([
        'uuid' => $uuid,
        'site_id' => $siteId,
        'department_id' => null,
        'first_name' => 'Persona',
        'last_name' => 'De Prueba',
        'employee_code' => 'E'.Str::upper(Str::random(9)),
        'email' => null,
        'status' => 'active',
        'hired_at' => '2026-01-01',
        'terminated_at' => null,
        'locale' => 'es',
        'created_at' => '2026-01-01 00:00:00+00',
        'updated_at' => '2026-01-01 00:00:00+00',
        ...$overrides,
    ]);

    return $uuid;
}

it('trata el codigo de empleado como CITEXT y no admite dos que solo difieran en mayusculas', function (): void {
    // Doc 01 §5.5: `employee_code` es CITEXT UNIQUE. Si fuera `varchar`, un
    // `e7qk2mxpr` y un `E7QK2MXPR` convivirian y el portal aceptaria los dos
    // como personas distintas.
    $site = siteForSchemaTest();

    insertEmployee($site, ['employee_code' => 'E7QK2MXPR']);

    try {
        insertEmployee($site, ['employee_code' => 'e7qk2mxpr']);
        Assert::fail('PostgreSQL deberia rechazar el codigo duplicado que solo difiere en mayusculas.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('employees_employee_code_unique');
    }
})->group('RF-GP-01', 'RF-ID-06');

it('admite toda la plantilla sin correo', function (): void {
    // Regla dura 12: el correo es opcional y su unicidad es un indice PARCIAL,
    // asi que muchos empleados sin correo no pueden chocar entre si. Si el
    // indice fuera completo sobre una columna con semantica distinta, el segundo
    // alta sin correo fallaria.
    $site = siteForSchemaTest();

    insertEmployee($site);
    insertEmployee($site);
    insertEmployee($site);

    expect(DB::table('employees')->whereNull('email')->count())->toBe(3);
})->group('RF-GP-01');

it('no admite dos empleados con el mismo correo aunque cambie la caja', function (): void {
    $site = siteForSchemaTest();

    insertEmployee($site, ['email' => 'lucia.ferrer@hotel.example']);

    try {
        insertEmployee($site, ['email' => 'Lucia.Ferrer@hotel.example']);
        Assert::fail('El correo es CITEXT: la unicidad no puede depender de las mayusculas.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('employees_email_unique');
    }
})->group('RF-GP-01');

it('no deja registrar una baja sin fecha de cese', function (): void {
    // RF-GP-03 y RL-02: sin fecha, la retencion no sabe desde cuando contar.
    $site = siteForSchemaTest();

    try {
        insertEmployee($site, ['status' => 'terminated', 'terminated_at' => null]);
        Assert::fail('La restriccion employees_chk_terminated_has_date deberia impedirlo.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('employees_chk_terminated_has_date');
    }
})->group('RF-GP-03');

it('no deja una fecha de cese anterior a la de alta', function (): void {
    $site = siteForSchemaTest();

    try {
        insertEmployee($site, [
            'status' => 'terminated',
            'hired_at' => '2026-05-01',
            'terminated_at' => '2026-04-30',
        ]);
        Assert::fail('La restriccion employees_chk_terminated_after_hired deberia impedirlo.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('employees_chk_terminated_after_hired');
    }
})->group('RF-GP-03');

it('conserva la fila del empleado dado de baja', function (): void {
    // Regla dura 5 y RF-GP-03: la baja es logica. La fila sigue ahi, con su
    // codigo, su UUID y su fecha de alta, porque el registro horario asociado se
    // conserva cuatro anos (RL-02).
    $site = siteForSchemaTest();
    $uuid = insertEmployee($site);

    DB::table('employees')->where('uuid', $uuid)->update([
        'status' => 'terminated',
        'terminated_at' => '2026-08-31',
    ]);

    $row = DB::table('employees')->where('uuid', $uuid)->first();

    expect($row)->not->toBeNull()
        ->and($row?->status)->toBe('terminated')
        ->and($row?->terminated_at)->toBe('2026-08-31');
})->group('RF-GP-03');

it('rechaza un estado que no esta en el catalogo', function (): void {
    $site = siteForSchemaTest();

    try {
        insertEmployee($site, ['status' => 'vacaciones']);
        Assert::fail('employees_chk_status deberia limitar el estado a los tres valores del doc 01 §5.5.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('employees_chk_status');
    }
})->group('RF-GP-01');

it('guarda el documento de identidad como digest de pgcrypto y nunca en claro', function (): void {
    // RL-08. El digest se calcula en la sentencia, con parametro enlazado, de
    // modo que el documento no queda ni en la columna ni en el texto de la
    // consulta.
    $site = siteForSchemaTest();
    $uuid = insertEmployee($site);

    DB::update(
        'UPDATE employees SET national_id_hash = digest(?, ?) WHERE uuid = ?',
        ['00000000T', 'sha256', $uuid],
    );

    // Se lee en hexadecimal desde SQL: la columna es `bytea` y el driver la
    // devuelve como flujo, no como cadena. Lo que importa comprobar es su
    // contenido, no como lo empaqueta PDO.
    /** @var object{hash: string|null}|null $row */
    $row = DB::selectOne(
        "SELECT encode(national_id_hash, 'hex') AS hash FROM employees WHERE uuid = ?",
        [$uuid],
    );

    $stored = $row?->hash;

    expect($stored)->toBeString()
        // 32 bytes de SHA-256, en hexadecimal.
        ->toHaveLength(64)
        // Ni el documento, ni nada que se le parezca.
        ->and($stored)->not->toContain(bin2hex('00000000T'));

    // Y es exactamente el SHA-256 del documento: comparable entre altas para
    // detectar duplicados sin guardar el original.
    expect($stored)->toBe(hash('sha256', '00000000T'));
})->group('RL-08', 'RF-GP-01');
