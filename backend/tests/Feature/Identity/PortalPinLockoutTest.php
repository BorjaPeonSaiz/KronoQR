<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El bloqueo creciente del PIN visto desde el portal (RS-12, doc 02 §7.5,
 * RF-ID-06).
 *
 * **Es el mecanismo de la tarea 1.12 reutilizado entero, no uno nuevo.** Los
 * tres escalones, la ventana deslizante y la clave con origen ya estan probados
 * en `tests/Unit/Shared/Domain/PinLockoutEscalationTest.php` (la tabla de
 * decision) y en `tests/Integration/Shared/PinAttemptsTest.php` (el contador
 * sobre la cache). Lo que falta y se prueba aqui es lo que solo se puede
 * comprobar por HTTP:
 *
 *   1. Que el endpoint del portal **cuenta contra `PinOrigin::PORTAL`** y no
 *      contra el del quiosco. Con el origen equivocado, sondear el portal de
 *      alguien le dejaria sin poder fichar a la mañana siguiente, que es la
 *      regla dura 19 provocada desde fuera.
 *   2. Que un empleado bloqueado en una puerta **sigue pudiendo intentarlo en la
 *      otra**, y al reves.
 *   3. Que el bloqueo **no se anuncia**: mismo `401` que un PIN incorrecto, sin
 *      `Retry-After` y sin `429`. Anunciarlo confirmaria que ese codigo de
 *      empleado existe (RS-03, regla dura 17).
 *
 * **El limite de tasa se desactiva a proposito en la mayoria de las pruebas.**
 * Diez peticiones por minuto son menos que los diez fallos del tercer escalon,
 * asi que con el limitador activo el `429` taparia el desenlace que se quiere
 * observar. Que ese limite existe y es independiente lo comprueba su propia
 * prueba, al final.
 */

uses(RefreshDatabase::class);

/**
 * Un empleado con PIN y el limite de tasa levantado.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoParaBloqueo(): array
{
    // Techo alto para que el limitador no interfiera: lo que se mide aqui es el
    // contador de FALLOS por empleado, que es otro control (§7.5).
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    // Los umbrales de serie, escritos para que la prueba no dependa del `.env`
    // de quien la ejecuta.
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);

    app(Cache::class)->clear();

    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del bloqueo'));

    EmployeePins::issue($uuid, PortalLogins::PIN);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

/**
 * El contador de intentos visto en un instante concreto, sobre la misma cache
 * que acaba de escribir el endpoint.
 *
 * El adaptador recibe el puerto `Clock` por constructor, asi que dos instancias
 * con dos relojes distintos sobre la misma cache son literalmente el mismo
 * contador visto en dos momentos. Es lo que permite comprobar «una hora de
 * bloqueo» sin esperar una hora.
 */
function contadorDelPortalEn(string $instante): PinAttempts
{
    return new CachePinAttempts(app(Cache::class), FixedClock::at($instante));
}

/**
 * Un intento de acceso al portal con el PIN equivocado.
 */
function fallaEnElPortal(string $codigo): void
{
    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $codigo,
        'pin' => '000999',
    ])->assertStatus(401);
}

/**
 * El mismo intento, pero con el reloj del servidor puesto en un instante.
 *
 * **Hay que olvidar la instancia del contador ademas de sustituir el `Clock`**:
 * `PinAttempts` esta declarado como singleton, asi que recibe su reloj una sola
 * vez y cambiar el del contenedor despues no le llegaria. Olvidando la
 * instancia, la peticion siguiente lo reconstruye con el reloj nuevo — que es lo
 * que en produccion pasa solo, porque cada peticion es un proceso.
 */
function fallaEnElPortalEn(string $codigo, string $instante): void
{
    app()->instance(Clock::class, FixedClock::at($instante));
    app()->forgetInstance(PinAttempts::class);

    fallaEnElPortal($codigo);
}

it('bloquea el portal al tercer fallo, y con el PIN correcto', function (): void {
    // El escalon 1 del §7.5: tres fallos, cinco minutos. Lo que hace que el
    // bloqueo signifique algo es la ULTIMA linea: con el PIN bueno tampoco se
    // entra. Si se entrara, el bloqueo seria decorativo.
    $empleado = empleadoParaBloqueo();

    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:00');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:01');

    // Todavia no: con dos fallos el PIN correcto entra.
    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);

    // Y acertar limpia el contador, asi que hacen falta tres fallos nuevos.
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:03');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:04');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:05');

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');
})->group('RS-12', 'RF-ID-06');

it('cuenta los fallos del portal contra su propio origen y no contra el del quiosco', function (): void {
    // §7.5: «por empleado y por origen». Es lo que impide que sondear el portal
    // de alguien —accesible desde la red interna del hotel, RF-ID-08— le deje
    // sin poder fichar a la mañana siguiente. La regla dura 19 al reves,
    // provocada desde fuera.
    $empleado = empleadoParaBloqueo();

    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:00');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:01');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:02');

    $contador = contadorDelPortalEn('2026-03-14 08:00:03');

    expect($contador->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeTrue()
        // La otra puerta sigue abierta: su tarjeta y su PIN de quiosco no se han
        // visto afectados.
        ->and($contador->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-ID-06', 'RF-AT-11');

it('deja intentarlo en el portal a quien esta bloqueado en el quiosco', function (): void {
    // El mismo reparto, en el otro sentido. Alguien que se equivoco tres veces
    // con guantes puestos delante de una tablet a las 06:00 sigue pudiendo
    // consultar sus horas desde el movil.
    $empleado = empleadoParaBloqueo();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 06:00:00'));

    $contador = app(PinAttempts::class);
    $contador->recordFailure($empleado['uuid'], PinOrigin::KIOSK);
    $contador->recordFailure($empleado['uuid'], PinOrigin::KIOSK);
    $contador->recordFailure($empleado['uuid'], PinOrigin::KIOSK);

    expect($contador->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeTrue();

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);
})->group('RS-12', 'RF-AT-11', 'RF-ID-06');

it('escala el bloqueo del portal en los tres escalones', function (): void {
    // 3 -> 5 min, 5 -> 15 min, 10 -> 60 min (§7.5), todo por el endpoint real.
    //
    // **Los fallos hay que repartirlos en el tiempo, y esa es la mitad de la
    // prueba.** Un empleado bloqueado NO acumula intentos: el verificador
    // devuelve «bloqueado» sin llegar a comparar el PIN, asi que insistir
    // durante el castigo no acerca al escalon siguiente. Para llegar a diez
    // fallos hay que esperar a que cada bloqueo termine, que es exactamente el
    // coste que el escalado impone a quien esta probando PIN — y por eso barrer
    // un espacio de 10^6 es inviable.
    $empleado = empleadoParaBloqueo();

    // Tres fallos seguidos: escalon 1, cinco minutos.
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:00');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:01');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:02');

    expect(contadorDelPortalEn('2026-03-14 08:05:01')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeTrue()
        ->and(contadorDelPortalEn('2026-03-14 08:05:03')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeFalse();

    // Cuarto fallo pasado el castigo: sigue en el escalon 1, otros cinco
    // minutos. Quinto fallo pasado ESE castigo: escalon 2, quince minutos.
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:05:04');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:10:05');

    expect(contadorDelPortalEn('2026-03-14 08:25:04')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeTrue()
        ->and(contadorDelPortalEn('2026-03-14 08:25:06')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeFalse();

    // Del sexto al decimo, cada uno esperando sus quince minutos: escalon 3, una
    // hora.
    foreach ([
        '2026-03-14 08:25:07',
        '2026-03-14 08:40:08',
        '2026-03-14 08:55:09',
        '2026-03-14 09:10:10',
        '2026-03-14 09:25:11',
    ] as $instante) {
        fallaEnElPortalEn($empleado['code'], $instante);
    }

    expect(contadorDelPortalEn('2026-03-14 10:25:10')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeTrue()
        ->and(contadorDelPortalEn('2026-03-14 10:25:12')->isLocked($empleado['uuid'], PinOrigin::PORTAL))->toBeFalse()
        // Y el contador del quiosco sigue a cero despues de diez fallos en el
        // portal: sondear una puerta no cierra la otra (§7.5, regla dura 19).
        ->and(contadorDelPortalEn('2026-03-14 10:00:00')->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-ID-06');

it('no anuncia el bloqueo ni con el codigo de estado ni con Retry-After', function (): void {
    // RS-03 y regla dura 17. Un `429` con `Retry-After` aqui —que es lo que hace
    // el acceso al PANEL, correctamente— confirmaria que ese codigo de empleado
    // existe, y ademas convertiria el bloqueo en un oraculo: bastaria con medir
    // cuando llega para saber si el PIN probado era el bueno.
    $empleado = empleadoParaBloqueo();

    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:00');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:01');
    fallaEnElPortalEn($empleado['code'], '2026-03-14 08:00:02');

    $bloqueado = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ]);

    $inexistente = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => 'ENOEXISTE1',
        'pin' => PortalLogins::PIN,
    ]);

    expect($bloqueado->getStatusCode())->toBe(401)
        ->and($bloqueado->getStatusCode())->toBe($inexistente->getStatusCode())
        ->and($bloqueado->getContent())->toBe($inexistente->getContent())
        ->and($bloqueado->headers->has('Retry-After'))->toBeFalse();
})->group('RS-03', 'RS-12');

it('limita las peticiones por origen ademas de contar los fallos por empleado', function (): void {
    // §7.1: el portal en 10 r/m. Es un control DISTINTO del bloqueo por
    // intentos y ninguno sustituye al otro: este frena a quien prueba un PIN de
    // mucha gente —cambiando el codigo en cada peticion, de modo que ningun
    // contador por empleado llega a levantarse— y aquel a quien prueba muchos
    // PIN de una persona.
    empleadoParaBloqueo();

    config()->set('identity.portal.rate_limit_per_minute', 3);

    $codigos = [];

    for ($intento = 1; $intento <= 4; $intento++) {
        // Codigo distinto en cada peticion: sin esto, lo que cortaria seria el
        // limite por codigo y no el de IP, y la prueba no distinguiria cual de
        // los dos actuo.
        $codigos[] = 'ENOEXISTE'.$intento;
    }

    $estados = [];

    foreach ($codigos as $codigo) {
        $estados[] = Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $codigo,
            'pin' => '000999',
        ])->getStatusCode();
    }

    expect($estados)->toBe([401, 401, 401, 429]);
})->group('RS-12', 'RS-02', 'RF-ID-06');
