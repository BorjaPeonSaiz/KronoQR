<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Application\Command\DeliverCredentialCommand;
use App\Modules\Identity\Application\Command\RetireSigningKeyCommand;
use App\Modules\Identity\Application\Command\RotateSigningKeyCommand;
use App\Modules\Identity\Application\Exception\SigningKeyRotationNotReady;
use App\Modules\Identity\Application\Exception\SigningKeyStillInUse;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Application\UseCase\RetireSigningKey;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Identity\Application\UseCase\SigningKeyRotationReport;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Rotacion de la clave de firma con solape (RF-QR-07, doc 02 §5.3, tarea 2.12).
 *
 * **Integracion y no unitaria**, y por dos motivos que son el nucleo de la
 * funcionalidad: la idempotencia la garantiza un indice parcial de PostgreSQL
 * —no una comprobacion en PHP— y la invariante que hace posible el solape
 * (`one_active_credential_per_key_and_employee`) solo existe en el esquema. Una
 * prueba con dobles diria que el codigo llama a lo que llama, no que nadie se
 * queda sin poder fichar.
 *
 * La suite corre con las dos claves configuradas (`phpunit.xml`): `a3` actual y
 * `a2` saliente. Ninguna de las dos es un secreto y ninguna sirve fuera de aqui.
 */

uses(RefreshDatabase::class);

/**
 * Un centro con `$employees` personas, cada una con su tarjeta **impresa**
 * firmada con la clave saliente. Es el estado del que parte una rotacion real:
 * todo el mundo fichando con la clave que se va.
 *
 * @return list<int> Los `employees.id`, en orden.
 */
function plantillaConTarjetaAntigua(int $employees = 3): array
{
    $site = WorkforceFixtures::site();
    $ids = [];

    for ($i = 0; $i < $employees; $i++) {
        $uuid = WorkforceFixtures::employee($site);
        /** @var int $id */
        $id = DB::table('employees')->where('uuid', $uuid)->value('id');

        Credentials::issueFor($id, Credentials::previousKey());

        $ids[] = $id;
    }

    return $ids;
}

function rotar(bool $dryRun = false): SigningKeyRotationReport
{
    return app(RotateSigningKey::class)->handle(new RotateSigningKeyCommand(dryRun: $dryRun));
}

it('reemite una credencial por tarjeta antigua sin invalidar ninguna vigente', function (): void {
    // El corazon de RF-QR-07: durante el solape **nadie se queda sin fichar**.
    $empleados = plantillaConTarjetaAntigua();

    $informe = rotar();

    expect($informe->retiringKeyId)->toBe('a2')
        ->and($informe->currentKeyId)->toBe('a3')
        ->and($informe->reissued)->toBe(3);

    foreach ($empleados as $empleadoId) {
        $filas = DB::table('credentials')->where('employee_id', $empleadoId)->get();

        expect($filas)->toHaveCount(2);

        // La vieja sigue viva y escaneable: no se ha tocado.
        $vieja = $filas->firstWhere('key_id', 'a2');
        expect($vieja?->revoked_at)->toBeNull()
            ->and($vieja?->printed_at)->not->toBeNull();

        // La nueva nace pendiente de imprimir: sin clave y sin hash (ADR-034).
        $nueva = $filas->firstWhere('key_id', null);
        expect($nueva?->printed_at)->toBeNull()
            ->and($nueva?->secret_hash)->toBeNull()
            ->and($nueva?->revoked_at)->toBeNull();
    }
})->group('RF-QR-07');

it('la tarjeta antigua sigue fichando despues de rotar', function (): void {
    // Lo que las filas dicen en la prueba anterior, comprobado por donde de
    // verdad importa: el resolvedor de credenciales del quiosco.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

    $tarjeta = Credentials::issueFor($employeeId, Credentials::previousKey());

    rotar();

    $resolucion = app(CredentialResolver::class)
        ->resolve($tarjeta->toString());

    expect($resolucion->isResolved())->toBeTrue()
        ->and($resolucion->employeeUuid())->toBe($uuid);
})->group('RF-QR-07', 'RN-15');

it('repetir la rotacion no reemite dos veces', function (): void {
    // Idempotencia. No la da una comprobacion amable: la da el indice parcial
    // `one_pending_credential_per_employee`, y la seleccion se salta a quien ya
    // tiene una pendiente para que el recuento del informe sea el real.
    plantillaConTarjetaAntigua();

    $primera = rotar();
    $segunda = rotar();

    expect($primera->reissued)->toBe(3)
        ->and($segunda->reissued)->toBe(0)
        ->and($segunda->alreadyPending)->toBe(3)
        ->and(DB::table('credentials')->count())->toBe(6);
})->group('RF-QR-07');

it('en seco informa y no escribe nada', function (): void {
    plantillaConTarjetaAntigua();

    $informe = rotar(dryRun: true);

    expect($informe->dryRun)->toBeTrue()
        ->and($informe->reissued)->toBe(3)
        ->and(DB::table('credentials')->count())->toBe(3)
        ->and(DB::table('audit_log')->count())->toBe(0);
})->group('RF-QR-07');

it('se niega a rotar cuando la configuracion no declara un solape', function (): void {
    plantillaConTarjetaAntigua();

    // Sin clave saliente no hay nada que relevar, y reemitir seria crear
    // sesenta tarjetas por un descuido de configuracion.
    Config::set('identity.credentials.signing_keys.previous.id', '');
    Config::set('identity.credentials.signing_keys.previous.secret', '');
    app()->forgetInstance(QrKeyProvider::class);

    expect(static fn () => rotar())->toThrow(SigningKeyRotationNotReady::class);
})->group('RF-QR-07');

it('se niega a rotar si una rotacion anterior dejo tarjetas huerfanas', function (): void {
    // Tarjetas activas firmadas con una clave que ya no esta en el llavero:
    // esas personas NO pueden fichar ahora mismo. Abrir otra rotacion encima
    // dejaria sin fichar tambien al grupo siguiente.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

    Credentials::issueFor($employeeId, Credentials::retiredKey());

    expect(static fn () => rotar())->toThrow(SigningKeyRotationNotReady::class);
})->group('RF-QR-07', 'RN-15');

it('al entregar la tarjeta nueva revoca la que releva', function (): void {
    // **El relevo, que es lo que hace que la rotacion termine.** Mientras la
    // tarjeta nueva esta en la mesa de RRHH las dos fichan —eso es el solape—;
    // en el momento en que se entrega, la vieja sobra y se retira sola. Sin
    // esto, el recuento de la clave saliente no baja nunca y la clave no se
    // puede retirar jamas.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');
    $responsable = ManagementUsers::withRole(UserRole::RRHH);

    Credentials::issueFor($employeeId, Credentials::previousKey());
    rotar();

    /** @var object{uuid: string, issued_at: string} $nueva */
    $nueva = DB::table('credentials')->where('employee_id', $employeeId)->whereNull('key_id')->first();

    // Se acuña como lo haria la impresora, sin arrancar un Chromium: lo que
    // esta prueba necesita es el estado, no el PDF. `printed_at` se toma de
    // `issued_at` porque `credentials_chk_lifecycle_order` no admite una
    // impresion anterior a la emision, y la reemision acaba de nacer.
    DB::table('credentials')->where('uuid', $nueva->uuid)->update([
        'key_id' => 'a3',
        'secret_hash' => Credentials::freshSecret()->hash(),
        'printed_at' => $nueva->issued_at,
    ]);

    app(DeliverCredential::class)->handle(new DeliverCredentialCommand(
        credentialUuid: $nueva->uuid,
        deliveredByUserId: $responsable->id,
    ));

    /** @var object{revoked_at: ?string, revoked_reason: ?string} $vieja */
    $vieja = DB::table('credentials')->where('employee_id', $employeeId)->where('key_id', 'a2')->first();
    /** @var object{revoked_at: ?string, delivered_at: ?string} $entregada */
    $entregada = DB::table('credentials')->where('uuid', $nueva->uuid)->first();

    expect($vieja->revoked_at)->not->toBeNull()
        ->and($vieja->revoked_reason)->toContain('a2')
        ->and($entregada->revoked_at)->toBeNull()
        ->and($entregada->delivered_at)->not->toBeNull()
        // Y la clave saliente ya no firma nada vivo: se puede retirar.
        ->and(DB::table('credentials')->where('key_id', 'a2')->whereNull('revoked_at')->count())->toBe(0);

    $acciones = DB::table('audit_log')->pluck('action')->countBy()->all();

    expect($acciones['credential.delivered'] ?? 0)->toBe(1)
        ->and($acciones['credential.revoked'] ?? 0)->toBe(1);
})->group('RF-QR-07', 'RF-QR-06', 'RL-04');

it('rechaza retirar la clave mientras quede una tarjeta activa firmada con ella', function (): void {
    plantillaConTarjetaAntigua();
    rotar();

    // Las tres tarjetas antiguas siguen vivas: retirar ahora dejaria a esas
    // tres personas delante del quiosco con un rechazo generico.
    expect(static fn () => app(RetireSigningKey::class)->handle(new RetireSigningKeyCommand('a2')))
        ->toThrow(SigningKeyStillInUse::class);
})->group('RF-QR-07');

it('acepta retirar la clave cuando ya no queda ninguna', function (): void {
    $empleados = plantillaConTarjetaAntigua();
    rotar();

    // El relevo: se revocan las antiguas, que es lo que hace la entrega de la
    // nueva. Aqui se abrevia al hecho que importa para la retirada.
    DB::table('credentials')
        ->where('key_id', 'a2')
        ->update(['revoked_at' => '2026-08-30 06:00:00+00', 'revoked_reason' => 'Rotacion']);

    $informe = app(RetireSigningKey::class)->handle(new RetireSigningKeyCommand('a2'));

    expect($informe->keyId)->toBe('a2')
        ->and($informe->signedCredentials)->toBe(\count($empleados));
})->group('RF-QR-07');

it('rechaza retirar la clave con la que se firma hoy', function (): void {
    plantillaConTarjetaAntigua();

    expect(static fn () => app(RetireSigningKey::class)->handle(new RetireSigningKeyCommand('a3')))
        ->toThrow(SigningKeyStillInUse::class);
})->group('RF-QR-07');

it('deja en audit_log la rotacion, cada reemision y la retirada', function (): void {
    // Regla dura 6 y RL-04: sin estos asientos, la unica explicacion de por que
    // una tarjeta de hace dos años dejo de verificar seria «alguien cambio una
    // variable de entorno», y eso no consta en ninguna parte.
    plantillaConTarjetaAntigua();

    rotar();

    $acciones = DB::table('audit_log')->pluck('action')->countBy()->all();

    expect($acciones['signing_key.rotated'] ?? 0)->toBe(1)
        ->and($acciones['credential.reissued'] ?? 0)->toBe(3);

    /** @var object{payload: string} $rotacion */
    $rotacion = DB::table('audit_log')->where('action', 'signing_key.rotated')->first();
    /** @var array<string, mixed> $payload */
    $payload = json_decode($rotacion->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['retiring_key_id'])->toBe('a2')
        ->and($payload['current_key_id'])->toBe('a3')
        ->and($payload['reissued'])->toBe(3);

    DB::table('credentials')
        ->where('key_id', 'a2')
        ->update(['revoked_at' => '2026-08-30 06:00:00+00', 'revoked_reason' => 'Rotacion']);

    app(RetireSigningKey::class)->handle(new RetireSigningKeyCommand('a2'));

    /** @var object{payload: string, subject_type: string} $retirada */
    $retirada = DB::table('audit_log')->where('action', 'signing_key.retired')->first();
    /** @var array<string, mixed> $retiradaPayload */
    $retiradaPayload = json_decode($retirada->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($retirada->subject_type)->toBe('signing_key')
        ->and($retiradaPayload['key_id'])->toBe('a2')
        // Ninguna clave, ningun token, ningun hash y ningun nombre en el trail.
        ->and(json_encode($retiradaPayload, JSON_THROW_ON_ERROR))
        ->not->toContain(Config::string('identity.credentials.signing_keys.previous.secret'));
})->group('RL-04', 'RF-QR-07');

it('los comandos de consola hacen lo mismo que los casos de uso', function (): void {
    plantillaConTarjetaAntigua();

    expect(Artisan::call('credentials:rotate-key', ['--dry-run' => true]))->toBe(0)
        ->and(DB::table('credentials')->count())->toBe(3);

    expect(Artisan::call('credentials:rotate-key'))->toBe(0)
        ->and(DB::table('credentials')->whereNull('printed_at')->count())->toBe(3);

    // Se niega mientras queden tarjetas vivas, y no con una excepcion: con un
    // codigo de salida distinto de cero y un mensaje que dice cuantas quedan.
    expect(Artisan::call('credentials:retire-key', ['key_id' => 'a2']))->toBe(1)
        ->and(Artisan::output())->toContain('3');

    DB::table('credentials')
        ->where('key_id', 'a2')
        ->update(['revoked_at' => '2026-08-30 06:00:00+00', 'revoked_reason' => 'Rotacion']);

    expect(Artisan::call('credentials:retire-key', ['key_id' => 'a2']))->toBe(0);
})->group('RF-QR-07');
