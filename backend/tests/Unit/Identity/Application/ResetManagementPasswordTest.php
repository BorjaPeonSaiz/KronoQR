<?php

declare(strict_types=1);

use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use Tests\Support\Database\AbortedTransactions;
use Tests\Support\Database\ImmediateTransactions;
use Tests\Support\Identity\InMemoryManagementAccounts;
use Tests\Support\Identity\RecordingAccessTokens;
use Tests\Support\Identity\RecordingIdentityEvents;
use Tests\Support\Time\FixedClock;

/*
 * La sustitucion de la contrasena de una cuenta de gestion, sin framework y sin
 * base de datos (**RS-06**, **RL-16**, OWASP A07; hallazgo **H-03** de la
 * revision interna ASVS de 2026-09).
 *
 * ## Que se prueba aqui y que no
 *
 * `ManagementAccountLifecycleCommandsTest` (Feature) ya comprueba el recorrido
 * entero: que la contrasena nueva sirve para entrar, que la anterior deja de
 * servir, que se enseña una sola vez y que cumple la politica de RF-ID-01. Eso
 * no se repite.
 *
 * Aqui se afirma lo que es del caso de uso (doc 02 §9.5): que solo actua sobre
 * una cuenta **activa**, que lo que publica es el hecho y nada mas, que la
 * contrasena llega al adaptador tal cual —ni recortada ni normalizada— y que ni
 * ella ni el correo salen en el evento que acaba en `audit_log`.
 *
 * **La politica de robustez no se comprueba en este nivel y no es un olvido.**
 * La contrasena la genera `ResetManagementPasswordCommand` (Infrastructure) con
 * un metodo privado que lee `config('identity.password.min_length')`, asi que
 * afirmarla exigiria el contenedor de servicios — y una prueba de Unit que
 * necesita el contenedor esta en la suite equivocada (`tests/Pest.php`). La
 * cubre la Feature `genera una contrasena que cumple la politica de robustez de
 * RF-ID-01`.
 *
 * **El reloj entra inyectado** (regla dura 2). En Unit no hay framework: el
 * doble es {@see FixedClock}, no `FrozenTime`.
 */

/**
 * El caso de uso con sus cuatro puertos doblados y el reloj detenido.
 */
function passwordResetHandlerFor(
    InMemoryManagementAccounts $accounts,
    RecordingAccessTokens $tokens,
    RecordingIdentityEvents $events,
): ResetManagementPasswordHandler {
    return new ResetManagementPasswordHandler(
        $accounts,
        $tokens,
        $events,
        FixedClock::at('2026-09-22 08:15:00'),
        ImmediateTransactions::connection(),
    );
}

/*
 * Las dos cuentas a las que no se les sustituye la contrasena. Una cuenta dada
 * de baja no recupera el acceso por cambiarsela, asi que hacerlo daria a
 * entender lo contrario a quien lo ejecuta.
 *
 * Cada padron va en una closure para que cada prueba monte el suyo y ninguna
 * herede lo que otra escribiera.
 */
dataset('cuentas de gestion a las que no se les cambia la contrasena', [
    'la cuenta existe pero esta dada de baja' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withDeactivatedAccount('jefatura@hotel.example'),
    ],
    'no hay ninguna cuenta con ese correo' => [
        fn (): InMemoryManagementAccounts => InMemoryManagementAccounts::withoutAnyAccount(),
    ],
]);

it('sustituye la contrasena de la cuenta activa y lo confirma', function (): void {
    $accounts = InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example');

    $replaced = passwordResetHandlerFor($accounts, new RecordingAccessTokens, new RecordingIdentityEvents)
        ->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    expect($replaced)->toBeTrue()
        ->and($accounts->passwords)->toHaveKey(InMemoryManagementAccounts::UUID);
})->group('RS-06', 'RL-16');

it('entrega al adaptador exactamente la contrasena que recibe', function (string $password): void {
    /*
     * El adaptador es quien hashea, y lo hace con lo que le llegue. Un recorte,
     * un `trim` o una normalizacion por el camino dejarian almacenado un hash de
     * algo distinto de lo que el comando acaba de enseñar en pantalla: quien la
     * anoto no podria entrar, y el sintoma —«la contrasena nueva no funciona»—
     * no apuntaria a este fichero.
     *
     * Los dos valores son los limites que importan: 12 caracteres es el minimo
     * de serie de `identity.password.min_length` y 20 es la longitud con la que
     * el comando la genera. Escritos, no calculados.
     */
    $accounts = InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example');

    passwordResetHandlerFor($accounts, new RecordingAccessTokens, new RecordingIdentityEvents)
        ->handle('jefatura@hotel.example', $password);

    expect($accounts->passwords[InMemoryManagementAccounts::UUID])->toBe($password);
})->with([
    'la longitud minima de la politica, 12 caracteres' => 'Rn7#tQ4vKp2$',
    'la que genera el comando, 20 caracteres' => 'Rn7#tQ4vKp2$Wm8xZc5j',
])->group('RS-06');

it('cierra todas las sesiones abiertas al sustituir la contrasena', function (): void {
    // De los dos motivos por los que se ejecuta esto —olvido y sospecha—, en el
    // que importa quien esta dentro lleva una sesion viva de hasta doce horas.
    // Cambiarle la contrasena sin cerrarla le retiraria una credencial que ya no
    // necesita.
    $tokens = new RecordingAccessTokens;

    passwordResetHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        $tokens,
        new RecordingIdentityEvents,
    )->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    expect($tokens->revokedAccounts)->toBe([InMemoryManagementAccounts::UUID]);
})->group('RS-05', 'RS-06');

it('publica el restablecimiento con el uuid, el actor y el instante del reloj inyectado', function (): void {
    $events = new RecordingIdentityEvents;

    passwordResetHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j', '0199c4a1-6f2d-7b10-9e3a-000000000042');

    $reset = $events->passwordReset();

    expect($reset->eventName())->toBe('identity.management_password_reset')
        ->and($reset->userUuid)->toBe(InMemoryManagementAccounts::UUID)
        ->and($reset->actorUuid)->toBe('0199c4a1-6f2d-7b10-9e3a-000000000042')
        ->and($reset->occurredAt()->format(DATE_ATOM))->toBe('2026-09-22T08:15:00+00:00');
})->group('RS-06', 'RL-16');

it('atribuye al sistema el restablecimiento que se ejecuta en consola sin sesion detras', function (): void {
    $events = new RecordingIdentityEvents;

    passwordResetHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    expect($events->passwordReset()->actorUuid)->toBeNull();
})->group('RL-16');

it('no lleva al evento la contrasena nueva, ni el correo, ni ningun dato mas que el uuid y el actor', function (): void {
    /*
     * El hecho auditable es que la credencial se sustituyo, no cual es: ni la
     * contrasena, ni nada derivado de ella, ni siquiera su longitud. Y regla
     * dura 21 para el correo, que ademas solo aparecio en la busqueda.
     *
     * Se afirma la lista **exacta** de campos publicos porque es lo unico que
     * detecta el campo nuevo que alguien añada mañana «para depurar».
     */
    $events = new RecordingIdentityEvents;

    passwordResetHandlerFor(
        InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example'),
        new RecordingAccessTokens,
        $events,
    )->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    $reset = $events->passwordReset();

    expect(array_keys(get_object_vars($reset)))->toBe(['userUuid', 'actorUuid'])
        ->and((string) json_encode($reset))->not->toContain('Rn7#tQ4vKp2$Wm8xZc5j')
        ->and((string) json_encode($reset))->not->toContain('jefatura@hotel.example');
})->group('RS-06', 'RL-16');

it('se niega sin cambiar nada, sin revocar y sin publicar cuando no hay cuenta activa', function (Closure $padron): void {
    $accounts = $padron();
    $tokens = new RecordingAccessTokens;
    $events = new RecordingIdentityEvents;

    $replaced = passwordResetHandlerFor($accounts, $tokens, $events)
        ->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    expect($replaced)->toBeFalse()
        ->and($accounts->passwords)->toBe([])
        ->and($tokens->revokedAccounts)->toBe([])
        ->and($events->published)->toBe([]);
})->with('cuentas de gestion a las que no se les cambia la contrasena')->group('RS-06', 'RL-16');

it('deja el cambio de contrasena, la revocacion y el asiento dentro de la transaccion, sin nada fuera', function (): void {
    /*
     * ADR-027: si el asiento falla, la contrasena anterior sigue siendo la
     * buena. Con la conexion que no ejecuta el cuerpo de la transaccion, lo que
     * se observe en los dobles es por definicion lo que el caso de uso hace
     * FUERA de ella — y tiene que ser nada. Una sustitucion de credencial sin
     * traza es el hecho que un administrador comprometido querria que no
     * constara.
     */
    $accounts = InMemoryManagementAccounts::withActiveAccount('jefatura@hotel.example');
    $tokens = new RecordingAccessTokens;
    $events = new RecordingIdentityEvents;

    $handler = new ResetManagementPasswordHandler(
        $accounts,
        $tokens,
        $events,
        FixedClock::at('2026-09-22 08:15:00'),
        AbortedTransactions::connection(),
    );

    $handler->handle('jefatura@hotel.example', 'Rn7#tQ4vKp2$Wm8xZc5j');

    expect($accounts->passwords)->toBe([])
        ->and($tokens->revokedAccounts)->toBe([])
        ->and($events->published)->toBe([]);
})->group('RS-06', 'RL-16');
