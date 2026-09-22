<?php

declare(strict_types=1);

use App\Modules\Identity\Application\UseCase\AccountDeactivationOutcome;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use Tests\Support\Database\AbortedTransactions;
use Tests\Support\Database\ImmediateTransactions;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\RecordingAccessTokens;
use Tests\Support\Identity\RecordingIdentityEvents;
use Tests\Support\Time\FixedClock;

/*
 * La baja de una cuenta de gestion, sin framework y sin base de datos
 * (**RS-05**, **RS-06**, **RL-16**; hallazgo **H-03** de la revision interna
 * ASVS de 2026-09).
 *
 * ## Que se prueba aqui y que no
 *
 * `ManagementAccountLifecycleCommandsTest` (Feature) ya recorre el comando de
 * punta a punta: que `users.is_active` queda a `false`, que el asiento llega a
 * `audit_log` y que la sesion abierta deja de valer en la peticion siguiente.
 * Eso no se repite.
 *
 * Lo que vive aqui es la **maquina de desenlaces** del caso de uso, que es una
 * regla de aplicacion y no del esquema (doc 02 §9.5): cuando hay baja y cuando
 * no, que se publica y que no se publica, y a quien se le cierran las sesiones.
 * Con los puertos doblados, los tres estados —activa, ya de baja, inexistente—
 * se montan en una linea cada uno; con base de datos costarian tres altas y una
 * migracion, y la suite no bajaria de 2 s.
 *
 * **El reloj entra inyectado** (regla dura 2): `occurred_at` del asiento es el
 * instante del puerto `Clock`, no el del dia en que se ejecuta la suite. En Unit
 * no hay framework, asi que el doble es {@see FixedClock} y no `FrozenTime`, que
 * es lo que exige `FrozenTimeTest` para Feature, Integration y Contract.
 */

/**
 * El caso de uso con sus cuatro puertos doblados y el reloj detenido.
 *
 * La transaccion **ejecuta** su cuerpo: lo que se observa despues en los dobles
 * es lo que el caso de uso hizo. La otra mitad —que no haga nada fuera de la
 * transaccion— se afirma abajo con {@see AbortedTransactions}.
 */
function deactivationHandlerFor(
    InMemoryManagementAccounts $accounts,
    RecordingAccessTokens $tokens,
    RecordingIdentityEvents $events,
): DeactivateManagementAccountHandler {
    return new DeactivateManagementAccountHandler(
        $accounts,
        $tokens,
        $events,
        FixedClock::at('2026-09-22 08:15:00'),
        ImmediateTransactions::connection(),
    );
}

/*
 * Los dos «no» de la baja: el padron y el desenlace que le toca a cada uno.
 *
 * El padron llega envuelto en una closure para que cada prueba monte el suyo:
 * un doble compartido entre dos pruebas arrastraria lo que la primera escribiera
 * y taparia justo el fallo que la segunda busca.
 */
dataset('desenlaces de una baja que no llega a ocurrir', [
    'la cuenta existe y ya estaba dada de baja' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withDeactivatedAccount('jefatura@hotel.example'),
        AccountDeactivationOutcome::AlreadyInactive,
    ],
    'no hay ninguna cuenta con ese correo' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withoutAnyAccount(),
        AccountDeactivationOutcome::NotFound,
    ],
]);

/*
 * Los mismos dos padrones sin el desenlace, para la prueba que habla de lo que
 * NO se escribe. Se declaran aparte y no se reutiliza el de arriba con un
 * parametro sobrante: un argumento que la prueba recibe y no mira es una
 * invitacion a afirmar sobre el sin querer.
 */
dataset('cuentas de gestion sin acceso activo', [
    'la cuenta existe y ya estaba dada de baja' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withDeactivatedAccount('jefatura@hotel.example'),
    ],
    'no hay ninguna cuenta con ese correo' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withoutAnyAccount(),
    ],
]);

it('da de baja la cuenta activa y lo dice con el desenlace Deactivated', function (): void {
    $accounts = InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example');

    $outcome = deactivationHandlerFor($accounts, new RecordingAccessTokens, new RecordingIdentityEvents)
        ->handle('jefatura@hotel.example', 'Baja al cierre de temporada');

    expect($outcome)->toBe(AccountDeactivationOutcome::Deactivated)
        ->and($accounts->deactivated)->toBe([InMemoryManagementAccounts::UUID]);
})->group('RS-05', 'RS-06', 'RL-16');

it('cierra todas las sesiones de la cuenta que acaba de dar de baja', function (): void {
    // La mitad que se olvida de una baja: marcar la cuenta y dejar viva la
    // sesion abierta en una tablet no da de baja a nadie durante las doce horas
    // siguientes, que es justo el tiempo que importa cuando la baja es por
    // sospecha y no por calendario.
    $tokens = new RecordingAccessTokens;

    deactivationHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        $tokens,
        new RecordingIdentityEvents,
    )->handle('jefatura@hotel.example', 'Cuenta comprometida');

    expect($tokens->revokedAccounts)->toBe([InMemoryManagementAccounts::UUID]);
})->group('RS-05', 'RS-06');

it('publica la baja con el uuid, el motivo, el actor y el instante del reloj inyectado', function (): void {
    $events = new RecordingIdentityEvents;

    deactivationHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Baja al cierre de temporada', '0199c4a1-6f2d-7b10-9e3a-000000000042');

    $deactivation = $events->deactivation();

    expect($deactivation->eventName())->toBe('identity.management_account_deactivated')
        ->and($deactivation->userUuid)->toBe(InMemoryManagementAccounts::UUID)
        ->and($deactivation->reason)->toBe('Baja al cierre de temporada')
        ->and($deactivation->actorUuid)->toBe('0199c4a1-6f2d-7b10-9e3a-000000000042')
        ->and($deactivation->occurredAt()->format(DATE_ATOM))->toBe('2026-09-22T08:15:00+00:00');
})->group('RS-05', 'RL-16');

it('atribuye al sistema la baja que se ejecuta en consola sin sesion detras', function (): void {
    // Atribuirsela a la ultima persona que entro al panel seria falsificar el
    // trail: `null` es la respuesta honesta y el listener la traduce a `system`.
    $events = new RecordingIdentityEvents;

    deactivationHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Fin de contrato');

    expect($events->deactivation()->actorUuid)->toBeNull();
})->group('RL-16');

it('no lleva a la baja publicada ni el correo ni ningun dato mas que el uuid, el motivo y el actor', function (): void {
    /*
     * Regla dura 21. El evento es lo que un listener de `Compliance` sella en
     * `audit_log`, y de ahi sale tambien el paquete de diagnostico que viaja al
     * fabricante: un correo o un nombre aqui es una fuga, y el trail no se puede
     * reescribir para quitarlo.
     *
     * Se afirma la lista **exacta** de campos publicos y no solo la ausencia del
     * correo: un campo nuevo con el nombre del titular pasaria desapercibido a
     * una comprobacion que solo buscara la direccion de este escenario.
     */
    $events = new RecordingIdentityEvents;

    deactivationHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Baja al cierre de temporada');

    $deactivation = $events->deactivation();

    expect(array_keys(get_object_vars($deactivation)))->toBe(['userUuid', 'reason', 'actorUuid'])
        ->and((string) json_encode($deactivation))->not->toContain('jefatura@hotel.example');
})->group('RS-05', 'RL-16');

it('distingue «ya estaba dada de baja» de «no existe esa cuenta»', function (
    Closure $padron,
    AccountDeactivationOutcome $expected
): void {
    // Confundir los dos mandaria al operador a buscar en el correo una errata
    // que no hay. No es un oraculo de enumeracion: esto es consola del servidor
    // del cliente, nunca HTTP.
    $outcome = deactivationHandlerFor($padron(), new RecordingAccessTokens, new RecordingIdentityEvents)
        ->handle('jefatura@hotel.example', 'Baja');

    expect($outcome)->toBe($expected);
})->with('desenlaces de una baja que no llega a ocurrir')->group('RS-05', 'RL-16');

it('no desactiva, no revoca y no publica nada cuando no hay cuenta activa con ese correo', function (
    Closure $padron
): void {
    // El trail cuenta HECHOS: repetir el comando no cambia nada, y un asiento
    // por repeticion seria una via para llenar la cadena de ADR-010 —por la que
    // pasa cada fichaje— con escrituras que no dicen nada nuevo.
    $accounts = $padron();
    $tokens = new RecordingAccessTokens;
    $events = new RecordingIdentityEvents;

    deactivationHandlerFor($accounts, $tokens, $events)->handle('jefatura@hotel.example', 'Baja');

    expect($accounts->deactivated)->toBe([])
        ->and($tokens->revokedAccounts)->toBe([])
        ->and($events->published)->toBe([]);
})->with('cuentas de gestion sin acceso activo')->group('RS-05', 'RS-06', 'RL-16');

it('deja la baja, la revocacion y el asiento dentro de la transaccion, sin nada fuera', function (): void {
    /*
     * ADR-027: si el asiento falla, la cuenta sigue activa. Con la conexion que
     * no ejecuta el cuerpo de la transaccion, lo que se observe en los dobles es
     * por definicion lo que el caso de uso hace FUERA de ella — y tiene que ser
     * nada. Que PostgreSQL revierta de verdad se prueba en Integration.
     */
    $accounts = InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example');
    $tokens = new RecordingAccessTokens;
    $events = new RecordingIdentityEvents;

    $handler = new DeactivateManagementAccountHandler(
        $accounts,
        $tokens,
        $events,
        FixedClock::at('2026-09-22 08:15:00'),
        AbortedTransactions::connection(),
    );

    $handler->handle('jefatura@hotel.example', 'Baja al cierre de temporada');

    expect($accounts->deactivated)->toBe([])
        ->and($tokens->revokedAccounts)->toBe([])
        ->and($events->published)->toBe([]);
})->group('RS-05', 'RL-16');
