<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las garantias de `credentials` que sostiene PostgreSQL (doc 01 §5.2 y §5.5,
 * RF-QR-01, RF-QR-03).
 *
 * Se escribe **por SQL directo**, sin pasar por el caso de uso: lo que se
 * comprueba es que las invariantes aguantan cuando alguien escribe SIN pasar
 * por el dominio —una importacion, un script de soporte, una condicion de
 * carrera—, que es justo donde el codigo de aplicacion no protege. En un sistema
 * con valor probatorio, la integridad no puede depender solo de PHP.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: int}
 */
function credentialContext(): array
{
    $site = WorkforceFixtures::site();
    WorkforceFixtures::employee($site);

    /** @var int $employeeId */
    $employeeId = DB::table('employees')->orderByDesc('id')->value('id');

    return ['site' => $site, 'employee' => $employeeId];
}

it('guarda el hash del token y nunca los 22 caracteres del token', function (): void {
    // Regla dura 10 y §5.2, paso 4. Es la comprobacion que la verificacion de la
    // tarea pide hacer a mano con psql: `SELECT secret_hash FROM credentials`
    // devuelve un hash, no un payload.
    $context = credentialContext();

    $payload = Credentials::issueFor($context['employee']);

    /** @var string $stored */
    $stored = DB::table('credentials')->where('employee_id', $context['employee'])->value('secret_hash');

    expect($stored)->toHaveLength(64)
        ->and($stored)->toMatch('/^[0-9a-f]{64}$/')
        ->and($stored)->not->toBe($payload->token)
        ->and($stored)->toBe(CredentialSecret::hashOf($payload->token));

    // Y el token no aparece en NINGUNA columna de la fila, no solo en esa.
    $row = DB::table('credentials')->where('employee_id', $context['employee'])->first();

    foreach ((array) $row as $column => $value) {
        if (\is_string($value)) {
            Assert::assertStringNotContainsString(
                $payload->token,
                $value,
                'La columna '.$column.' contiene el token en claro.',
            );
        }
    }
})->group('RF-QR-01', 'RS-01', 'RL-08');

it('no admite dos tarjetas vivas del mismo empleado firmadas con la misma clave', function (): void {
    // Invariante del agregado del doc 01 §5.2, declarada como indice parcial
    // `one_active_credential_per_key_and_employee` (tarea 2.12). Es la que
    // impide el caso que importa: dos tarjetas escaneables **indistinguibles**,
    // una de ellas perdida. Reemitir por perdida obliga a revocar la anterior.
    $context = credentialContext();

    Credentials::issueFor($context['employee']);

    expect(static fn () => Credentials::issueFor($context['employee']))
        ->toThrow(QueryException::class);
})->group('RF-QR-03', 'RN-13');

it('si admite dos tarjetas vivas del mismo empleado con claves distintas', function (): void {
    // **El caso positivo de la rotacion con solape** (RF-QR-07, §5.3): la
    // tarjeta que se lleva encima y su relevo ya impreso conviven mientras la
    // segunda no se entrega. Sin esto habria que reimprimir toda la plantilla en
    // un solo dia, que es exactamente lo que el `key_id` existe para evitar.
    $context = credentialContext();

    Credentials::issueFor($context['employee'], Credentials::previousKey());
    Credentials::issueFor($context['employee'], Credentials::currentKey());

    $vivas = DB::table('credentials')
        ->where('employee_id', $context['employee'])
        ->whereNull('revoked_at');

    expect($vivas->count())->toBe(2)
        ->and($vivas->orderBy('key_id')->pluck('key_id')->all())->toBe(['a2', 'a3']);
})->group('RF-QR-07', 'RF-QR-03');

it('no admite dos credenciales pendientes de imprimir del mismo empleado', function (): void {
    // La otra mitad de la invariante, `one_pending_credential_per_employee`: es
    // lo que hace que `credentials:rotate-key` sea idempotente por construccion
    // —la segunda pasada choca con la fila que dejo la primera— y lo que impide
    // que una emision duplicada acabe en dos tarjetas impresas.
    $context = credentialContext();

    Credentials::pendingFor($context['employee']);

    expect(static fn () => Credentials::pendingFor($context['employee']))
        ->toThrow(QueryException::class);
})->group('RF-QR-07', 'RF-QR-03');

it('admite otra credencial activa cuando la anterior esta revocada', function (): void {
    // La otra mitad del indice parcial: la reemision tiene que ser posible.
    $context = credentialContext();

    Credentials::issueFor($context['employee'], revokedReason: 'Perdida');
    Credentials::issueFor($context['employee']);

    expect(DB::table('credentials')->where('employee_id', $context['employee'])->count())->toBe(2)
        ->and(DB::table('credentials')->where('employee_id', $context['employee'])->whereNull('revoked_at')->count())->toBe(1);
})->group('RF-QR-03');

it('no admite una revocacion sin motivo', function (): void {
    // `credentials_chk_revocation_has_reason`. Una revocacion sin motivo es un
    // hueco en la explicacion de por que alguien dejo de poder fichar.
    $context = credentialContext();

    expect(static fn () => DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $context['employee'],
        'key_id' => 'a3',
        'secret_hash' => hash('sha256', 'x'),
        'issued_at' => '2026-08-14 06:00:00+00',
        'printed_at' => '2026-08-14 06:00:00+00',
        'revoked_at' => '2026-08-15 06:00:00+00',
        'revoked_reason' => null,
    ]))->toThrow(QueryException::class);
})->group('RF-QR-03');

it('no admite una revocacion anterior a la emision', function (): void {
    // `credentials_chk_lifecycle_order`.
    $context = credentialContext();

    expect(static fn () => DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $context['employee'],
        'key_id' => 'a3',
        'secret_hash' => hash('sha256', 'x'),
        'issued_at' => '2026-08-14 06:00:00+00',
        'printed_at' => '2026-08-14 06:00:00+00',
        'revoked_at' => '2026-08-13 06:00:00+00',
        'revoked_reason' => 'Imposible',
    ]))->toThrow(QueryException::class);
})->group('RF-QR-03');

it('no admite dos veces la misma pareja key_id y hash', function (): void {
    // `credentials_key_id_secret_hash_unique`: es el indice por el que resuelve
    // el fichaje, y una colision haria ambigua la resolucion de una tarjeta.
    $context = credentialContext();
    $site = $context['site'];

    WorkforceFixtures::employee($site);
    /** @var int $otro */
    $otro = DB::table('employees')->orderByDesc('id')->value('id');

    $hash = hash('sha256', 'mismo-token');

    DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $context['employee'],
        'key_id' => 'a3',
        'secret_hash' => $hash,
        'issued_at' => '2026-08-14 06:00:00+00',
        'printed_at' => '2026-08-14 06:00:00+00',
    ]);

    expect(static fn () => DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $otro,
        'key_id' => 'a3',
        'secret_hash' => $hash,
        'issued_at' => '2026-08-14 06:00:00+00',
        'printed_at' => '2026-08-14 06:00:00+00',
    ]))->toThrow(QueryException::class);
})->group('RF-QR-02');

it('da a cada credencial un identificador publico unico', function (): void {
    // `credentials_uuid_unique`, de la migracion de esta tarea. La clave interna
    // no sale de la base de datos: la URL de revocacion habla en UUID.
    $context = credentialContext();

    Credentials::issueFor($context['employee']);

    /** @var string $uuid */
    $uuid = DB::table('credentials')->where('employee_id', $context['employee'])->value('uuid');

    expect($uuid)->toMatch('/^[0-9a-f-]{36}$/');

    WorkforceFixtures::employee($context['site']);
    /** @var int $otro */
    $otro = DB::table('employees')->orderByDesc('id')->value('id');

    expect(static fn () => DB::table('credentials')->insert([
        'uuid' => $uuid,
        'employee_id' => $otro,
        'key_id' => 'a3',
        'secret_hash' => hash('sha256', 'otro'),
        'issued_at' => '2026-08-14 06:00:00+00',
        'printed_at' => '2026-08-14 06:00:00+00',
    ]))->toThrow(QueryException::class);
})->group('RF-QR-03');

it('admite una credencial pendiente de imprimir, sin clave ni hash', function (): void {
    // ADR-034: emitir no acuña ningun token. La fila existe con `key_id` y
    // `secret_hash` nulos, y en ese estado no la resuelve ninguna busqueda por
    // hash: nadie puede fichar con ella.
    $context = credentialContext();

    Credentials::pendingFor($context['employee']);

    $row = DB::table('credentials')->where('employee_id', $context['employee'])->first();

    expect($row?->key_id)->toBeNull()
        ->and($row?->secret_hash)->toBeNull()
        ->and($row?->printed_at)->toBeNull();
})->group('RF-QR-01', 'RF-QR-08');

it('admite varias credenciales pendientes a la vez pese al indice unico de clave y hash', function (): void {
    // Un indice unico de PostgreSQL trata cada NULL como distinto
    // (`NULLS DISTINCT`, el comportamiento por defecto). Si algun dia alguien lo
    // declarara `NULLS NOT DISTINCT`, «pendiente de imprimir» pasaria a ser un
    // estado del que solo cabe uno en toda la instalacion, y el alta de una
    // temporada entera fallaria en la segunda persona. Esta prueba es la que se
    // entera.
    $context = credentialContext();

    WorkforceFixtures::employee($context['site']);
    /** @var int $otro */
    $otro = DB::table('employees')->orderByDesc('id')->value('id');

    Credentials::pendingFor($context['employee']);
    Credentials::pendingFor($otro);

    expect(DB::table('credentials')->whereNull('secret_hash')->count())->toBe(2);
})->group('RF-QR-01', 'RF-QR-04');

it('no admite media impresion', function (array $minted): void {
    // `credentials_chk_minted_at_print`: las tres marcas van juntas. Un hash sin
    // `printed_at` dejaria una tarjeta escaneable que el panel sigue contando
    // como pendiente; un `printed_at` sin hash, una tarjeta que nadie puede usar.
    $context = credentialContext();

    expect(static fn () => DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $context['employee'],
        'issued_at' => '2026-08-14 06:00:00+00',
        ...$minted,
    ]))->toThrow(QueryException::class);
})->with([
    'hash sin fecha de impresion' => [['key_id' => 'a3', 'secret_hash' => 'h']],
    'fecha de impresion sin hash' => [['printed_at' => '2026-08-15 06:00:00+00']],
    'clave sin hash' => [['key_id' => 'a3', 'printed_at' => '2026-08-15 06:00:00+00']],
])->group('RF-QR-04');

it('no admite una entrega sin responsable, sin impresion o anterior a ella', function (array $delivery): void {
    // `credentials_chk_delivery_is_signed` (RF-QR-06). La entrega es un acto
    // presencial con fecha y responsable, y antes de imprimir no hay nada que
    // entregar.
    $context = credentialContext();
    $responsable = ManagementUsers::withRole(UserRole::RRHH)->id;

    if (\array_key_exists('delivered_by_user_id', $delivery)) {
        $delivery['delivered_by_user_id'] = $responsable;
    }

    try {
        DB::table('credentials')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $context['employee'],
            'issued_at' => '2026-08-14 06:00:00+00',
            ...$delivery,
        ]);
    } catch (QueryException $exception) {
        // Se comprueba **que restriccion** salta y no solo que salta alguna: con
        // un `delivered_by_user_id` inventado, la clave ajena tambien lanzaria
        // `QueryException` y la prueba pasaria sin haber probado nada.
        expect($exception->getMessage())->toContain('credentials_chk_delivery_is_signed');

        return;
    }

    Assert::fail('PostgreSQL ha aceptado una entrega que no puede existir.');
})->with([
    'sin responsable' => [[
        'key_id' => 'a3',
        'secret_hash' => 'h1',
        'printed_at' => '2026-08-15 06:00:00+00',
        'delivered_at' => '2026-08-16 06:00:00+00',
    ]],
    'sin imprimir' => [[
        'delivered_at' => '2026-08-16 06:00:00+00',
        'delivered_by_user_id' => 0,
    ]],
    'antes de imprimir' => [[
        'key_id' => 'a3',
        'secret_hash' => 'h2',
        'printed_at' => '2026-08-17 06:00:00+00',
        'delivered_at' => '2026-08-16 06:00:00+00',
        'delivered_by_user_id' => 0,
    ]],
])->group('RF-QR-06');

it('no borra la credencial de un empleado, ni siquiera borrando al empleado', function (): void {
    // Regla dura 5: `restrictOnDelete`. La credencial revocada de alguien que ya
    // no trabaja aqui es parte del registro que se conserva cuatro anos (RL-02).
    $context = credentialContext();

    Credentials::issueFor($context['employee']);

    expect(static fn () => DB::table('employees')->where('id', $context['employee'])->delete())
        ->toThrow(QueryException::class);
})->group('RL-02', 'RF-GP-03');
