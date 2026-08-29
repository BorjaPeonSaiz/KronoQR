<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\VerifyTwoFactorCommand;
use App\Modules\Identity\Application\Exception\AuthenticationFailed;
use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\UseCase\VerifyTwoFactorHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\RecordingTwoFactorAuthenticator;
use Tests\Support\Identity\RecordingTwoFactorSecrets;

/*
 * RS-03 y regla dura 17 en el camino del segundo factor: **una cuenta sin TOTP
 * activo cuesta lo mismo que un codigo equivocado**.
 *
 * ## Que se afirmaba y no era cierto
 *
 * El docblock de `VerifyTwoFactorHandler` dice que las cuatro causas de rechazo
 * —codigo equivocado, codigo caducado, codigo ya usado y cuenta sin segundo factor
 * activo— producen el mismo `401` y no se distinguen. La respuesta si era la misma;
 * **el trabajo no**: la rama sin secreto se saltaba la consulta de la ultima franja
 * aceptada y, sobre todo, los HMAC de la ventana de tolerancia. Un `401` rapido
 * decia «esta cuenta todavia no tiene segundo factor», que es exactamente la que
 * mas interesa a quien ya tiene una contrasena: puede darsela de alta el mismo.
 *
 * ## Por que se cuentan operaciones y no milisegundos
 *
 * Igual que en {@see \Tests\Integration\Workforce\PinRejectionSymmetryTest} y en
 * `ConstantTimeRejectionTest`. La diferencia son microsegundos y un cronometro en
 * la CI daria una prueba intermitente; **la secuencia de operaciones no es
 * intermitente**: si alguien vuelve a poner un atajo «porque no hay secreto contra
 * el que comparar», esto falla aunque el reloj no lo note.
 *
 * ## No es lo mismo que el rechazo del acceso
 *
 * Aqui quien pregunta ya acerto una contrasena, asi que no hay enumeracion de
 * cuentas que proteger. Lo que se protege es la enumeracion del **estado del
 * segundo factor** de las cuentas cuya contrasena ya se tiene, que es el paso
 * previo a quedarse uno con el TOTP de otro.
 */

uses(RefreshDatabase::class);

/**
 * El caso de uso con los dos puertos espiados, ya puestos en el contenedor.
 *
 * @return array{handler: VerifyTwoFactorHandler, secrets: RecordingTwoFactorSecrets, authenticator: RecordingTwoFactorAuthenticator}
 */
function verificadorEspiado(): array
{
    $secrets = new RecordingTwoFactorSecrets(app(TwoFactorSecrets::class));
    $authenticator = new RecordingTwoFactorAuthenticator(app(TwoFactorAuthenticator::class));

    app()->instance(TwoFactorSecrets::class, $secrets);
    app()->instance(TwoFactorAuthenticator::class, $authenticator);

    return [
        'handler' => app(VerifyTwoFactorHandler::class),
        'secrets' => $secrets,
        'authenticator' => $authenticator,
    ];
}

function ordenDeCodigoMalo(string $uuid): VerifyTwoFactorCommand
{
    return new VerifyTwoFactorCommand(
        userUuid: $uuid,
        code: '000000',
        deviceName: 'Panel de gestion',
        challengeTokenId: 0,
        // Clave propia por prueba: lo que se mide es el camino del rechazo, no el
        // bloqueo, que tiene la suya en `TwoFactorRateLimitTest`.
        throttleKey: '2fa|'.$uuid,
    );
}

beforeEach(function (): void {
    app(Cache::class)->clear();

    // Techo alto: ninguna de estas pruebas debe cruzar el flanco del bloqueo, que
    // anade operaciones por su cuenta y es otro camino con su propia prueba.
    config()->set('identity.two_factor.max_attempts', 500);
});

it('ejecuta la misma secuencia de operaciones tenga o no la cuenta un segundo factor activo', function (): void {
    $conTotp = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($conTotp);

    $sinTotp = ManagementUsers::withRole(UserRole::AUDITOR);

    ['handler' => $handler, 'secrets' => $secrets, 'authenticator' => $authenticator] = verificadorEspiado();

    expect(fn () => $handler->handle(ordenDeCodigoMalo($conTotp->uuid)))
        ->toThrow(AuthenticationFailed::class);

    $conSecreto = $secrets->drain();
    $verificacionesConSecreto = $authenticator->verifications;

    expect(fn () => $handler->handle(ordenDeCodigoMalo($sinTotp->uuid)))
        ->toThrow(AuthenticationFailed::class);

    $sinSecreto = $secrets->drain();

    expect($sinSecreto)->toBe($conSecreto)
        // Y no esta vacia: una implementacion que no tocara el almacen en ninguna
        // de las dos ramas tambien pasaria la comparacion de arriba.
        ->and($conSecreto)->toBe(['activeSecretFor', 'lastAcceptedSliceFor'])
        // El trabajo caro —los HMAC de la ventana— se paga en las dos. Es lo que
        // el señuelo existe para garantizar.
        ->and($authenticator->verifications - $verificacionesConSecreto)->toBe(1)
        ->and($verificacionesConSecreto)->toBe(1);
})->group('RS-03', 'RS-06', 'RF-ID-01');

it('ejecuta el mismo numero de consultas tenga o no la cuenta un segundo factor activo', function (): void {
    // La otra mitad estructural, la misma que fija `PinRejectionSymmetryTest`:
    // buscar una franja que no esta cuesta la misma consulta que encontrarla.
    $conTotp = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($conTotp);

    $sinTotp = ManagementUsers::withRole(UserRole::AUDITOR);

    $handler = app(VerifyTwoFactorHandler::class);
    $consultas = [];

    foreach (['con TOTP' => $conTotp->uuid, 'sin TOTP' => $sinTotp->uuid] as $caso => $uuid) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $handler->handle(ordenDeCodigoMalo($uuid));
        } catch (AuthenticationFailed) {
            // El desenlace esperado en los dos casos; lo que se mide es el coste.
        }

        $consultas[$caso] = \count(DB::getRawQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($consultas)))
        ->toHaveCount(1, 'Los rechazos no cuestan lo mismo: '.json_encode($consultas));
})->group('RS-03', 'RS-06', 'RF-ID-01');
