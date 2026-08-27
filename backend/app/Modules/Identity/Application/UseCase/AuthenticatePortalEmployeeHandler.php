<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\AuthenticatePortalEmployeeCommand;
use App\Modules\Identity\Application\Exception\PortalAccessDenied;
use App\Modules\Identity\Application\Support\PortalAccessTelemetry;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PortalSessionIssuer;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Domain\ValueObject\PortalSession;
use DateTimeImmutable;

/**
 * **Abre la sesion del portal personal** (RF-ID-05, RF-ID-06, RL-05, art. 34.9
 * ET).
 *
 * Existe por obligacion legal: la persona trabajadora tiene que poder acceder a
 * su propio registro de jornada. Si esto no funciona, el cliente incumple.
 *
 * ## Comprueba quien es, y nada mas
 *
 * Igual que `RegisterPinScanHandler` hace con el fichaje de respaldo, este caso
 * de uso hace **solo** la parte que le toca —resolver la identidad y acuñar la
 * sesion— y no duplica ni una linea de lo que ya existe:
 *
 * ```
 * Shared\Application\Port\EmployeePinVerifier   compara el PIN, en tiempo constante,
 *                                              y lleva el contador de intentos
 * Shared\Application\Port\PortalSessionIssuer   acuña el token colgado del empleado
 * ```
 *
 * **El contador de intentos no se toca desde aqui**, y es deliberado: lo lleva
 * el verificador, con `PinOrigin::PORTAL`, que es lo que garantiza que las dos
 * puertas —quiosco y portal— no puedan implementar la mitad cada una. El puerto
 * `Shared\Application\Port\PinAttempts` de la tarea 1.12 se reutiliza **entero y
 * sin tocarlo**: sus tres escalones, su ventana deslizante y su clave con origen
 * ya estaban preparados para esto.
 *
 * ## Cinco desenlaces, un solo rechazo
 *
 * ```
 * codigo inexistente         -> PortalAccessDenied
 * PIN incorrecto             -> PortalAccessDenied   (+ 1 fallo en el contador)
 * PIN nunca emitido          -> PortalAccessDenied   (+ 1 fallo en el contador)
 * empleado no en alta        -> PortalAccessDenied   (+ 1 fallo en el contador)
 * bloqueo por intentos       -> PortalAccessDenied   (sin comparar el PIN)
 * ```
 *
 * Los cinco salen como el mismo `401` y en tiempo constante (RS-03, regla dura
 * 17). El tiempo lo iguala el verificador comparando siempre contra **algo**
 * —el hash real o el señuelo—, asi que aqui no hace falta ningun suelo
 * artificial; lo unico que este caso de uso tiene que hacer es no ramificar
 * hacia respuestas distintas.
 *
 * **El sexto desenlace es una carrera y sale igual**: que la persona deje de
 * existir entre la comprobacion del PIN y la emision del token. El puerto
 * devuelve `null` y aqui es el mismo rechazo, no un `500`.
 *
 * ## El PIN deja de existir en cuanto se ha usado
 *
 * `sodium_memzero()`, por lo mismo que en el fichaje por PIN: la variable local
 * de este metodo puede aparecer en el volcado de una excepcion, y de ahi al
 * paquete de diagnostico que viaja al fabricante (ADR-020, regla dura 21).
 *
 * ## Sin transaccion, y no es un olvido
 *
 * No hay agregado que cargar ni proyeccion que recalcular: entrar al portal no
 * cambia el registro horario de nadie. La unica escritura es la fila del token,
 * que Sanctum hace atomica por si sola. La regla «un caso de uso, una
 * transaccion» describe los que **escriben dominio**, y este no escribe ninguno.
 *
 * ## Sin asiento en `audit_log`, y esto si conviene explicarlo
 *
 * RS-05 exige constancia del acceso a datos personales **de terceros**, y aqui
 * no hay tercero: es la propia persona entrando a ver sus horas. Ademas, el
 * catalogo de actores de `audit_log` no tiene hoy un tipo para un empleado
 * —solo `user`, `device`, `system` y `maintenance`—, asi que un apunte saldria
 * atribuido a `system`, que seria una entrada que miente en la tabla que se
 * enseña en una inspeccion. Si el producto decide que quiere constancia de los
 * accesos al portal, es un cambio del dominio de auditoria y de su restriccion
 * de esquema, no una linea de este fichero.
 */
final readonly class AuthenticatePortalEmployeeHandler
{
    public function __construct(
        private EmployeePinVerifier $pins,
        private PortalSessionIssuer $sessions,
        private Clock $clock,
        private PortalAccessTelemetry $telemetry,
        /**
         * Vida de la sesion, en horas, ya resuelta de la configuracion (regla
         * dura 13 y 14): el caso de uso no consulta `config()`.
         */
        private int $sessionHours,
    ) {}

    /**
     * @throws PortalAccessDenied ante cualquiera de los cinco desenlaces de rechazo
     */
    public function handle(AuthenticatePortalEmployeeCommand $command): PortalSession
    {
        return $this->telemetry->measure(fn (): PortalSession => $this->authenticate($command));
    }

    private function authenticate(AuthenticatePortalEmployeeCommand $command): PortalSession
    {
        $pin = $command->pin;

        $verification = $this->pins->verify($command->employeeCode, $pin, PinOrigin::PORTAL);

        sodium_memzero($pin);

        if ($verification->isLocked()) {
            $this->telemetry->locked($verification->retryAfterSeconds());

            throw new PortalAccessDenied;
        }

        $employeeUuid = $verification->employeeUuid();

        if ($employeeUuid === null) {
            $this->telemetry->rejected();

            throw new PortalAccessDenied;
        }

        $session = $this->sessions->issueFor(
            employeeUuid: $employeeUuid,
            // El nombre del token no lleva ningun dato personal (regla dura 21):
            // el UUID basta para reconocer la sesion al listarla.
            sessionName: 'portal:'.$employeeUuid,
            // Un solo ambito, y sale del enum y no de una cadena escrita aqui:
            // es lo unico que impide que la sesion de un empleado acabe con
            // `employees:*` el dia que alguien copie y pegue otro emisor.
            abilities: [TokenAbility::SELF_READ->value],
            expiresAt: $this->expiry(),
        );

        if (! $session instanceof PortalSession) {
            $this->telemetry->rejected();

            throw new PortalAccessDenied;
        }

        $this->telemetry->succeeded($session->employeeUuid);

        return $session;
    }

    /**
     * El instante lo da el puerto `Clock` (ADR-021, regla dura 2), nunca
     * `now()`: es lo que permite probar la caducidad sin esperar.
     */
    private function expiry(): DateTimeImmutable
    {
        return $this->clock->now()->modify('+'.max(1, $this->sessionHours).' hours');
    }
}
