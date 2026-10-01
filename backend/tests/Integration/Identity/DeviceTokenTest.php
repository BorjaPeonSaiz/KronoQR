<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Command\RevokeDeviceTokenCommand;
use App\Modules\Identity\Application\Command\RotateDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Identity\Application\UseCase\RotateDeviceTokenIfDue;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
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

/**
 * La clave del token en `personal_access_tokens`: la mitad de `<id>|<secreto>`
 * que no es secreta.
 */
function kioskTokenId(string $plainTextToken): int
{
    return (int) explode('|', $plainTextToken, 2)[0];
}

/**
 * Envejece el token vigente del dispositivo hasta dejarlo pasado el 80 % de su
 * vida (dia 80 de 90) respecto al reloj detenido.
 *
 * Se envejece la fila en lugar de mover el reloj: lo que decide es la fecha de
 * emision del token, y asi la prueba comprueba exactamente lo que ocurrira
 * dentro de 72 dias.
 */
function envejeceTokenDeQuiosco(int $deviceId, int $restanDias = 10): void
{
    DB::table('personal_access_tokens')->where('tokenable_id', $deviceId)->update([
        'created_at' => now()->subDays(90 - $restanDias),
        'expires_at' => now()->addDays($restanDias),
    ]);
}

function rotaTokenDeQuiosco(string $deviceUuid, string $presentado): ?IssuedAccessToken
{
    return app(RotateDeviceTokenIfDue::class)->handle(
        new RotateDeviceTokenCommand($deviceUuid, kioskTokenId($presentado)),
    );
}

/**
 * @return array{old: IssuedAccessToken, new: IssuedAccessToken, device: array{uuid: string, id: int}}
 */
function quioscoRecienRotado(): array
{
    $device = kioskDevice();
    $old = issuedKioskToken($device['uuid']);
    envejeceTokenDeQuiosco($device['id']);

    $new = rotaTokenDeQuiosco($device['uuid'], $old->plainTextToken);

    if (! $new instanceof IssuedAccessToken) {
        throw new RuntimeException('La rotacion no ha emitido relevo.');
    }

    return ['old' => $old, 'new' => $new, 'device' => $device];
}

function caducidadDelToken(string $plainTextToken): ?string
{
    $value = DB::table('personal_access_tokens')->where('id', kioskTokenId($plainTextToken))->value('expires_at');

    return is_string($value) ? $value : null;
}

it('no rota el token antes del 80 % de su vida', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $device = kioskDevice();

    $token = issuedKioskToken($device['uuid']);

    expect(rotaTokenDeQuiosco($device['uuid'], $token->plainTextToken))->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->count())->toBe(1);
})->group('RF-ID-04');

it('rota pasado el 80 % y deja el token anterior en solape de 24 horas', function (): void {
    // ADR-044. Antes, rotar borraba el anterior en la misma transaccion: si la
    // respuesta del latido se perdia, la tablet se quedaba con un token muerto.
    FrozenTime::at('2026-06-01 08:00:00');

    $rotado = quioscoRecienRotado();

    expect($rotado['new']->plainTextToken)->not->toBe($rotado['old']->plainTextToken)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $rotado['device']['id'])->count())->toBe(2)
        ->and(PersonalAccessToken::findToken($rotado['old']->plainTextToken))->not->toBeNull()
        ->and(caducidadDelToken($rotado['old']->plainTextToken))->toStartWith('2026-06-02 08:00:00')
        ->and($rotado['new']->expiresAt->format('Y-m-d H:i:s'))->toBe('2026-08-30 08:00:00')
        ->and(PersonalAccessToken::findToken($rotado['new']->plainTextToken)?->abilities)->toEqualCanonicalizing([
            TokenAbility::SCAN_WRITE->value,
            TokenAbility::ROSTER_READ->value,
            TokenAbility::HEARTBEAT_WRITE->value,
        ]);
})->group('RF-ID-04', 'RS-04');

it('el solape nunca pasa de la caducidad propia del token relevado', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $device = kioskDevice();
    $old = issuedKioskToken($device['uuid']);

    // Le quedan dos horas: el solape de 24 h no puede alargarle la vida.
    DB::table('personal_access_tokens')->where('tokenable_id', $device['id'])->update([
        'created_at' => now()->subDays(90)->addHours(2),
        'expires_at' => now()->addHours(2),
    ]);

    expect(rotaTokenDeQuiosco($device['uuid'], $old->plainTextToken))->not->toBeNull()
        ->and(caducidadDelToken($old->plainTextToken))->toStartWith('2026-06-01 10:00:00');
})->group('RF-ID-04');

it('un token en solape no rota otra vez: reentrega otro relevo sin alargar su solape', function (): void {
    // La respuesta que llevaba el primer relevo se perdio y la tablet vuelve con
    // el viejo. Se retira el relevo que no llego —nunca se uso— y se emite otro;
    // el solape del viejo conserva su fecha. Nunca hay mas de dos tokens.
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    FrozenTime::at('2026-06-01 20:00:00');
    $reentregado = rotaTokenDeQuiosco($rotado['device']['uuid'], $rotado['old']->plainTextToken);

    expect($reentregado)->not->toBeNull()
        ->and($reentregado?->plainTextToken)->not->toBe($rotado['new']->plainTextToken)
        ->and(PersonalAccessToken::findToken($rotado['new']->plainTextToken))->toBeNull()
        ->and(caducidadDelToken($rotado['old']->plainTextToken))->toStartWith('2026-06-02 08:00:00')
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $rotado['device']['id'])->count())->toBe(2);
})->group('RF-ID-04', 'RS-04');

it('el relevo recien emitido no vuelve a rotar hasta su propio 80 %', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    expect(rotaTokenDeQuiosco($rotado['device']['uuid'], $rotado['new']->plainTextToken))->toBeNull()
        // Y ya que firma el nuevo, el viejo sobra: la red de seguridad del
        // primer uso lo retira.
        ->and(PersonalAccessToken::findToken($rotado['old']->plainTextToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($rotado['new']->plainTextToken))->not->toBeNull();
})->group('RF-ID-04');

it('el primer uso del relevo retira en el acto el token al que releva', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    Api::as($rotado['old']->plainTextToken)->get('/api/v1/kiosk/roster')->assertOk();
    Api::as($rotado['new']->plainTextToken)->get('/api/v1/kiosk/roster')->assertOk();

    expect(PersonalAccessToken::findToken($rotado['old']->plainTextToken))->toBeNull();

    Api::as($rotado['old']->plainTextToken)->get('/api/v1/kiosk/roster')->assertUnauthorized();
    Api::as($rotado['new']->plainTextToken)->get('/api/v1/kiosk/roster')->assertOk();
})->group('RF-ID-04', 'RS-04');

it('el token relevado deja de valer al vencer el solape aunque el nuevo no se haya usado', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    FrozenTime::at('2026-06-02 07:59:00');
    Api::as($rotado['old']->plainTextToken)->get('/api/v1/kiosk/roster')->assertOk();

    FrozenTime::at('2026-06-02 08:00:01');
    Api::as($rotado['old']->plainTextToken)->get('/api/v1/kiosk/roster')->assertUnauthorized();
    Api::as($rotado['new']->plainTextToken)->get('/api/v1/kiosk/roster')->assertOk();
})->group('RF-ID-04', 'RS-04');

it('revocar un quiosco en pleno solape deja fuera los dos tokens en el acto', function (): void {
    // RS-04: la revocacion no tiene solape. Es la respuesta a una tablet robada.
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    app(RevokeDeviceToken::class)->handle(new RevokeDeviceTokenCommand($rotado['device']['uuid'], 'Tablet robada'));

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $rotado['device']['id'])->count())->toBe(0);

    Api::as($rotado['old']->plainTextToken)->get('/api/v1/kiosk/roster')->assertUnauthorized();
    Api::as($rotado['new']->plainTextToken)->get('/api/v1/kiosk/roster')->assertUnauthorized();
})->group('RS-04', 'RF-ID-04');

it('volver a emparejar retira tambien el token que estaba en solape', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();

    $emparejado = issuedKioskToken($rotado['device']['uuid']);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $rotado['device']['id'])->count())->toBe(1)
        ->and(PersonalAccessToken::findToken($emparejado->plainTextToken))->not->toBeNull();
})->group('RF-ID-04', 'RS-04');

it('deja constancia de la rotacion y de la reentrega sin el valor del token', function (): void {
    // Regla dura 6 y RS-04: cambia que token puede registrar fichajes. Solo el
    // dispositivo y fechas; ni el token ni su hash.
    FrozenTime::at('2026-06-01 08:00:00');
    $rotado = quioscoRecienRotado();
    rotaTokenDeQuiosco($rotado['device']['uuid'], $rotado['old']->plainTextToken);

    /** @var list<array<string, mixed>> $asientos */
    $asientos = DB::table('audit_log')
        ->where('subject_type', 'device')
        ->where('action', 'device.paired')
        ->orderBy('id')
        ->pluck('payload')
        ->map(static fn (mixed $payload): array => (array) json_decode(is_string($payload) ? $payload : '{}', true))
        ->values()
        ->all();

    $hash = DB::table('devices')->where('id', $rotado['device']['id'])->value('token_hash');
    $hash = is_string($hash) ? $hash : 'sin-hash';

    expect($asientos)->toHaveCount(3)
        ->and($asientos[1]['rotation'] ?? null)->toBeTrue()
        ->and($asientos[1]['redelivery'] ?? null)->toBeFalse()
        ->and($asientos[1]['superseded_until'] ?? null)->toBe('2026-06-02T08:00:00+00:00')
        ->and($asientos[1]['device_uuid'] ?? null)->toBe($rotado['device']['uuid'])
        ->and($asientos[2]['redelivery'] ?? null)->toBeTrue()
        ->and($asientos[2]['superseded_until'] ?? null)->toBe('2026-06-02T08:00:00+00:00');

    $texto = json_encode($asientos, JSON_THROW_ON_ERROR);

    expect($texto)->not->toContain(explode('|', $rotado['new']->plainTextToken)[1])
        ->and($texto)->not->toContain(explode('|', $rotado['old']->plainTextToken)[1])
        ->and($texto)->not->toContain($hash);
})->group('RF-ID-04', 'RS-07', 'RS-04');

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
