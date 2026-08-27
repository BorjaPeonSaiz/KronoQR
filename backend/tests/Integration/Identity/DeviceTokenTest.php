<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Command\RevokeDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Identity\Application\UseCase\RotateDeviceTokenIfDue;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Tokens de dispositivo (RF-ID-04, RS-04, doc 02 §7.3).
 *
 * La promesa que se comprueba aqui es la del §7.3: *«un token de quiosco
 * comprometido no da acceso a la plantilla completa»*. La sostienen tres cosas y
 * las tres se prueban: los ambitos que lleva, que el token en claro no se
 * almacena, y que revocar el dispositivo tiene efecto inmediato y no en 90 dias.
 */

uses(RefreshDatabase::class);

/**
 * @return array{uuid: string, id: int}
 */
function kioskDevice(string $status = 'active'): array
{
    $uuid = Str::uuid7()->toString();

    $id = DB::table('devices')->insertGetId([
        'uuid' => $uuid,
        'site_id' => WorkforceFixtures::site(),
        'name' => 'Quiosco '.Str::random(5),
        'status' => $status,
        'pending_queue_size' => 0,
        'created_at' => '2026-01-01 00:00:00+00',
        'updated_at' => '2026-01-01 00:00:00+00',
    ]);

    return ['uuid' => $uuid, 'id' => $id];
}

/**
 * Emite el token del dispositivo y **falla si no se emitio**.
 *
 * El caso de uso devuelve `null` cuando el dispositivo no existe o esta
 * revocado, y ese desenlace tiene su propia prueba. Aqui, un `null` significa
 * que la prueba no esta comprobando lo que dice comprobar, asi que se corta en
 * seco en lugar de arrastrar un `?->` por todo el fichero.
 */
function issuedKioskToken(string $deviceUuid): IssuedAccessToken
{
    $token = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($deviceUuid));

    if (! $token instanceof IssuedAccessToken) {
        throw new RuntimeException('No se ha emitido token para el dispositivo '.$deviceUuid.'.');
    }

    return $token;
}

it('emite un token con los tres ambitos del §7.3 y ninguno mas', function (): void {
    $device = kioskDevice();

    $stored = PersonalAccessToken::findToken(issuedKioskToken($device['uuid'])->plainTextToken);

    expect($stored)->not->toBeNull()
        ->and($stored?->abilities)->toEqualCanonicalizing([
            TokenAbility::SCAN_WRITE->value,
            TokenAbility::ROSTER_READ->value,
            TokenAbility::HEARTBEAT_WRITE->value,
        ]);
})->group('RF-ID-04', 'RS-04');

it('no concede al quiosco ningun ambito de gestion', function (): void {
    // La comprobacion explicita de RS-04: si algun dia alguien copia el emisor de
    // sesiones para el quiosco, esta prueba lo caza antes que una auditoria.
    $device = kioskDevice();

    $stored = PersonalAccessToken::findToken(issuedKioskToken($device['uuid'])->plainTextToken);

    /** @var list<string> $abilities */
    $abilities = $stored instanceof PersonalAccessToken ? $stored->abilities : [];

    foreach ([TokenAbility::EMPLOYEES_ALL, TokenAbility::CREDENTIALS_ALL, TokenAbility::REPORTS_ALL, TokenAbility::AUDIT_READ] as $prohibido) {
        expect($abilities)->not->toContain($prohibido->value);
    }
})->group('RS-04', 'RF-ID-04');

it('guarda el hash del token del dispositivo y nunca el token', function (): void {
    // Regla dura 21 y RS-04. La copia en `devices.token_hash` existe para que la
    // fila del dispositivo se explique sola, y es el MISMO hash que guarda
    // Sanctum: son dos apuntes del mismo valor, no dos valores.
    $device = kioskDevice();

    $plain = issuedKioskToken($device['uuid'])->plainTextToken;

    /** @var string $hash */
    $hash = DB::table('devices')->where('id', $device['id'])->value('token_hash');

    /** @var string $sanctum */
    $sanctum = DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->value('token');

    expect($hash)->toHaveLength(64)
        ->and($hash)->not->toBe($plain)
        ->and($hash)->toBe($sanctum)
        ->and($plain)->not->toBe('')
        ->and(str_contains($hash, explode('|', $plain)[1] ?? 'x'))->toBeFalse();
})->group('RS-04', 'RF-ID-04');

it('caduca a los 90 dias', function (): void {
    // §7.3. No es una sesion: nadie va a escribir una contrasena en la tablet
    // cada manana.
    $device = kioskDevice();

    $token = issuedKioskToken($device['uuid']);

    $dias = (int) round(($token->expiresAt->getTimestamp() - time()) / 86400);

    expect($dias)->toBe(config()->integer('identity.devices.token_days'))
        ->and($dias)->toBe(90);
})->group('RF-ID-04');

it('emitir de nuevo retira el token anterior', function (): void {
    // Una tablet tiene un token, no una coleccion: si quedaran dos vivos,
    // revocar el visible dejaria el otro funcionando.
    $device = kioskDevice();

    $primero = issuedKioskToken($device['uuid']);
    $segundo = issuedKioskToken($device['uuid']);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->count())->toBe(1)
        ->and(PersonalAccessToken::findToken($primero->plainTextToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($segundo->plainTextToken))->not->toBeNull();
})->group('RF-ID-04', 'RS-04');

it('no emite token a un dispositivo revocado', function (): void {
    $device = kioskDevice('revoked');

    expect(app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid'])))->toBeNull();
})->group('RS-04');

it('revocar el dispositivo borra su token y lo deja fuera en el acto', function (): void {
    // La respuesta a una tablet robada. Si dependiera de la caducidad del token,
    // esa tablet seguiria fichando tres meses.
    $device = kioskDevice();

    app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid']));

    $revocado = app(RevokeDeviceToken::class)->handle(new RevokeDeviceTokenCommand(
        deviceUuid: $device['uuid'],
        reason: 'Tablet sustituida',
    ));

    expect($revocado)->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->count())->toBe(0)
        ->and(DB::table('devices')->where('id', $device['id'])->value('token_hash'))->toBeNull()
        ->and(DB::table('devices')->where('id', $device['id'])->value('status'))->toBe('revoked');
})->group('RS-04', 'RF-ID-04');

it('no rota el token antes del 80 % de su vida', function (): void {
    $device = kioskDevice();

    app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid']));

    expect(app(RotateDeviceTokenIfDue::class)->handle($device['uuid']))->toBeNull();
})->group('RF-ID-04');

it('rota el token pasado el 80 % de su vida', function (): void {
    // §7.3. Se envejece la fila en lugar de mover el reloj de la aplicacion: lo
    // que decide es la fecha de emision del token, y asi la prueba comprueba
    // exactamente lo que ocurrira dentro de 72 dias.
    $device = kioskDevice();

    $primero = issuedKioskToken($device['uuid']);

    DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->update([
        'created_at' => now()->subDays(80),
        'expires_at' => now()->addDays(10),
    ]);

    $rotado = app(RotateDeviceTokenIfDue::class)->handle($device['uuid']);

    expect($rotado)->not->toBeNull()
        ->and($rotado?->plainTextToken)->not->toBe($primero->plainTextToken)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->count())->toBe(1);
})->group('RF-ID-04');

it('deja constancia en audit_log de la emision y de la revocacion', function (): void {
    // Regla dura 6: cambia quien puede escribir en el registro horario.
    $device = kioskDevice();

    app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid']));
    app(RevokeDeviceToken::class)->handle(new RevokeDeviceTokenCommand($device['uuid'], 'Tablet sustituida'));

    /** @var list<string> $acciones */
    $acciones = DB::table('audit_log')
        ->where('subject_type', 'device')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($acciones)->toBe(['device.paired', 'device.revoked']);
})->group('RF-ID-04', 'RS-07');
