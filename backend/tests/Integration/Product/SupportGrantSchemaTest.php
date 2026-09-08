<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * El esquema de `support_grants` dice lo mismo que el codigo (RF-PD-11, RNF-D-04).
 *
 * ## Por que hace falta una prueba para esto
 *
 * La migracion escribe el catalogo de alcances **a mano**:
 *
 *     CHECK (scope IN ('diagnostics', 'read_only', 'configuration'))
 *
 * y tiene que escribirlo a mano: el esquema de una instalacion no puede depender
 * de una clase de la aplicacion —es el mismo motivo por el que la migracion del
 * catalogo de roles repite los ambitos, y el propio `TokenAbility` lo declara—.
 * Una migracion ya aplicada en casa de un cliente no se reescribe.
 *
 * El precio es una copia que se puede separar del enum, y **separarse no rompe
 * nada visible**: un alcance nuevo en `SupportScope` sin su valor en el `CHECK`
 * no falla al desplegar, falla la primera vez que alguien concede un acceso con
 * el —en mitad de una incidencia, que es cuando se conceden—. Al reves es peor:
 * un valor de mas en el `CHECK` deja entrar por `psql` una fila cuyo alcance no
 * sabe resolver nadie.
 *
 * Asi que la copia la ata esta prueba, que es el mismo trato que tienen las tres
 * copias de los ambitos de token.
 */

uses(RefreshDatabase::class);

/**
 * La definicion que PostgreSQL guarda de una restriccion.
 *
 * Se pregunta al catalogo del motor y no a la migracion: lo que importa no es lo
 * que el fichero dice que iba a crear, sino lo que la base de datos **tiene**.
 * Una migracion que se aplico a medias o un `ALTER` hecho a mano se ven aqui.
 */
function definicionDeRestriccion(string $nombre): string
{
    $filas = DB::select(
        'SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conname = ?',
        [$nombre],
    );

    if ($filas === []) {
        return '';
    }

    $definicion = $filas[0]->definicion ?? null;

    return \is_string($definicion) ? $definicion : '';
}

it('el CHECK de scope admite exactamente los alcances del enum', function (): void {
    $definicion = definicionDeRestriccion('support_grants_chk_scope');

    expect($definicion)->not->toBe('', 'No existe la restriccion `support_grants_chk_scope`.');

    // Los literales que el `CHECK` enumera, en el orden en que PostgreSQL los
    // devuelve. Se comparan como CONJUNTO: lo que importa es que sean los
    // mismos, no como los normalice el motor.
    preg_match_all("/'([a-z_]+)'/", $definicion, $matches);

    $enElEsquema = array_values(array_unique($matches[1]));
    $enElCodigo = SupportScope::names();

    sort($enElEsquema);
    sort($enElCodigo);

    expect($enElEsquema)->toBe(
        $enElCodigo,
        'El CHECK de `support_grants.scope` y `SupportScope` han dejado de decir lo mismo. '
        .'Esquema: '.implode(', ', $enElEsquema).' · Codigo: '.implode(', ', $enElCodigo),
    );
})->group('RF-PD-11', 'RNF-D-04');

it('la base de datos rechaza un alcance que el enum no conoce', function (): void {
    // La otra mitad: que el `CHECK` este VIGENTE y no solo declarado. Es la red
    // que atrapa un `INSERT` hecho a mano en una madrugada de incidencia.
    $usuario = DB::table('users')->insertGetId([
        'uuid' => '0199f6a2-1111-7d3b-8a90-1b2c3d4e5f60',
        'name' => 'Cuenta de prueba',
        'email' => 'esquema@kronoqr.test',
        'password' => 'irrelevante',
        'locale' => 'es',
        'is_active' => true,
    ]);

    expect(fn () => DB::table('support_grants')->insert([
        'uuid' => '0199f6a2-2222-7d3b-8a90-1b2c3d4e5f60',
        'granted_by_user_id' => $usuario,
        'reason' => 'Alcance inventado',
        'scope' => 'todo',
        'granted_at' => now(),
        'expires_at' => now()->addDay(),
    ]))->toThrow(QueryException::class);
})->group('RF-PD-11', 'RNF-D-04');

it('la base de datos rechaza una concesion que caduca antes de existir', function (): void {
    // La segunda invariante declarada en el esquema: `expires_at > granted_at`.
    // Una concesion que nace caducada no es un acceso, es una fila incoherente.
    $usuario = DB::table('users')->insertGetId([
        'uuid' => '0199f6a2-3333-7d3b-8a90-1b2c3d4e5f60',
        'name' => 'Cuenta de prueba',
        'email' => 'esquema2@kronoqr.test',
        'password' => 'irrelevante',
        'locale' => 'es',
        'is_active' => true,
    ]);

    expect(fn () => DB::table('support_grants')->insert([
        'uuid' => '0199f6a2-4444-7d3b-8a90-1b2c3d4e5f60',
        'granted_by_user_id' => $usuario,
        'reason' => 'Caduca antes de nacer',
        'scope' => 'diagnostics',
        'granted_at' => now(),
        'expires_at' => now()->subHour(),
    ]))->toThrow(QueryException::class);
})->group('RF-PD-11', 'RNF-D-04');

it('el CHECK de audit_log admite el actor de soporte y sigue validado', function (): void {
    // La migracion expand. `convalidated` a `true` es lo que distingue una
    // restriccion en vigor de una que se quedo `NOT VALID` a medias.
    $definicion = definicionDeRestriccion('audit_log_chk_actor_type');

    expect($definicion)->toContain("'support_grant'");

    $filas = DB::select(
        'SELECT convalidated FROM pg_constraint WHERE conname = ?',
        ['audit_log_chk_actor_type'],
    );

    expect($filas)->not->toBe([]);
    expect((bool) ($filas[0]->convalidated ?? false))->toBeTrue();
})->group('RF-PD-11', 'RNF-D-04');
