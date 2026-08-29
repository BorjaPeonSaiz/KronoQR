<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterPinScanCommand;
use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\SealedPinOpener;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/**
 * **Ficha a quien no lleva su tarjeta** (RF-AT-11, RS-12).
 *
 * El doc 01 §3.1 explica por que no es un extra: *«es lo que impide que una
 * tarjeta olvidada se convierta en una jornada sin registro y en una correccion
 * manual»*. Y el §11.2 lo cuantifica: sin esta via, un olvido produce una
 * jornada escrita a mano, todos los dias.
 *
 * ## Resuelve quien es, y nada mas
 *
 * Este caso de uso hace **solo** la parte que el camino de la tarjeta no puede
 * hacer —abrir el sobre y comprobar el PIN— y delega el resto en
 * {@see RegisterScanHandler}, que es el caso de uso central del producto. No hay
 * aqui ni una regla de fichaje: quien decide si el escaneo abre o cierra turno
 * sigue siendo el agregado `WorkDay`, quien decide el anti-rebote sigue siendo
 * `DebouncePolicy`, y la transaccion, la proyeccion de `daily_totals`, la
 * auditoria, la idempotencia por UNIQUE y el `worked_minutes` que se fija una
 * sola vez siguen ocurriendo exactamente donde ya ocurrian.
 *
 * **Reutilizar y no copiar es la decision importante de esta clase.** Un segundo
 * camino de fichaje seria un segundo sitio donde equivocarse con cualquiera de
 * esas seis cosas, y con `worked_minutes` en el reenvio ya se fallo una vez
 * (tarea 1.7): un fichaje por PIN reenviado desde la cola offline devolvia el
 * acumulado de hoy en lugar del que tenia cuando ocurrio. Compartiendo el
 * metodo, ese arreglo vale para las dos vias sin que nadie tenga que acordarse.
 *
 * ## Tres desenlaces del PIN, un solo rechazo
 *
 * ```
 * el sobre no abre        -> rechazo generico
 * el PIN no verifica      -> rechazo generico   (+ 1 fallo en el contador)
 * el empleado esta bloqueado -> rechazo generico
 * ```
 *
 * Los tres se traducen a `CredentialRejectionReason::UNKNOWN`, que es el mismo
 * valor con el que se rechaza una tarjeta desconocida: desde fuera, y tambien
 * desde `scan_events.result`, un PIN incorrecto es indistinguible de un codigo
 * que no existe y de un bloqueo activo (regla dura 17, RS-03). El desenlace
 * detallado no se pierde: viaja al log estructurado y a la metrica, que es donde
 * el §8.2 lo quiere y donde no lo ve quien teclea.
 *
 * **El sobre que no abre no cuenta como intento fallido**, y esa distincion
 * importa: un criptograma corrupto no dice nada sobre el PIN que lleva dentro, y
 * contarlo permitiria bloquear el PIN de cualquiera enviando basura con su
 * codigo de empleado —la regla dura 19 rota desde fuera—. Quien lleva la cuenta
 * es el verificador, y solo cuando hubo un PIN de verdad contra un hash de
 * verdad.
 *
 * ## El rastro de autenticacion, y por que el exito no deja asiento
 *
 * OWASP A09. Los tres desenlaces suman en
 * `kronoqr_auth_attempts_total{channel="kiosk_pin"}` y cada rechazo escribe
 * `auth.login_failed` en el log tecnico. **Ninguno escribe en `audit_log`** —solo
 * lo hace el bloqueo, desde el verificador y despues de responder—, y el exito
 * tampoco: el fichaje que viene detras ya deja `shift_entry.created` con el mismo
 * empleado y el mismo instante. El reparto y su porque estan en
 * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`.
 *
 * ## Marcado para revision, no rechazado
 *
 * RF-AT-11 y el §7.5: *«en el quiosco, el fichaje por PIN queda marcado para
 * revision del responsable, lo que hace visible cualquier uso anomalo»*. La
 * marca la escribe `RegisterScanHandler` en `scan_events.flagged_for_review` a
 * partir del origen —`ReviewPolicy` la decide, y tambien marca por desfase de
 * reloj (RN-15)—, y la bandeja que la trabaja es la tarea 2.5. **Marcar no es
 * rechazar** (regla dura 19): el fichaje se registra igual y con la misma traza
 * que el de una tarjeta, porque un empleado que no puede fichar es una jornada
 * perdida y una correccion manual.
 *
 * Los rechazos **no** se marcan: `flagged_for_review` alimenta una bandeja de
 * fichajes que revisar, y un intento que no produjo tramo no es un fichaje. Su
 * rastro esta en `scan_events` con su `result` y en la metrica.
 */
final readonly class RegisterPinScanHandler
{
    public function __construct(
        private SealedPinOpener $sealedPins,
        private EmployeePinVerifier $pins,
        private RegisterScanHandler $scans,
        private EmployeeDirectory $employees,
        private ScanMetrics $metrics,
        private AuthenticationJournal $journal,
    ) {}

    public function handle(RegisterPinScanCommand $command): RegisterScanResult
    {
        $resolution = $this->resolve($command);

        $result = $this->scans->handle(
            new RegisterScanCommand(
                scanId: $command->scanId,
                // Sin tarjeta no hay payload ni huella que guardar.
                qrPayload: null,
                occurredAt: $command->occurredAt,
                deviceId: $command->deviceId,
                deviceUuid: $command->deviceUuid,
                // Lo fija el caso de uso y no la peticion: si el cliente pudiera
                // elegirlo, un fichaje por PIN podria presentarse como un
                // escaneo de tarjeta y esquivar la marca de revision.
                origin: ScanOrigin::PIN_KIOSK,
                intent: $command->intent,
                clientMeta: $command->clientMeta,
            ),
            $resolution,
        );

        $this->countFallback($result);

        return $result;
    }

    /**
     * Del sobre cerrado y el codigo de empleado al portador, o al rechazo.
     *
     * Devuelve el mismo tipo que el `CredentialResolver` de la tarjeta a
     * proposito: es lo que permite que `RegisterScanHandler` no sepa —ni tenga
     * que saber— por que puerta entro este fichaje.
     */
    private function resolve(RegisterPinScanCommand $command): CredentialResolution
    {
        $pin = $this->sealedPins->open($command->sealedPin);

        if ($pin === null) {
            // El sobre que no abre es el unico rechazo de esta via que el
            // verificador no llega a ver, asi que su apunte sale de aqui. Lleva
            // motivo propio porque **no es un intento fallido de nadie**: un
            // criptograma corrupto no dice nada del PIN que lleva dentro, y
            // contarlo como fallo permitiria bloquear a cualquiera enviando
            // basura con su codigo de empleado.
            $this->journal->failed(AuthChannel::KIOSK_PIN, null, AuthFailureReason::SEALED_PIN_UNREADABLE);

            return CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN);
        }

        $verification = $this->pins->verify($command->employeeCode, $pin, PinOrigin::KIOSK);

        // El PIN en claro deja de existir en cuanto se ha comprobado. No es
        // ceremonia: `$pin` es una variable local de un metodo que puede
        // aparecer en el volcado de una excepcion, y de ahi al paquete de
        // diagnostico que viaja al fabricante (ADR-020, regla dura 21).
        sodium_memzero($pin);

        $employeeUuid = $verification->employeeUuid();

        if ($employeeUuid === null) {
            // Sin apunte aqui: los dos desenlaces que caben en esta rama —PIN
            // incorrecto y bloqueo activo— ya los ha registrado el verificador,
            // que es el unico que sabe cual de los dos fue. Repetirlo daria dos
            // incrementos por intento y una tasa de fallo del doble de la real.
            return CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN);
        }

        $this->journal->succeeded(AuthChannel::KIOSK_PIN, $employeeUuid);

        return CredentialResolution::resolved($employeeUuid);
    }

    /**
     * `pin_fallback_scans_total{site}` (doc 02 §8.2).
     *
     * El §8.2 explica para que sirve: *«una subida indica un problema con la
     * emision, el estado de las tarjetas o la disciplina de la plantilla. Es un
     * termometro barato»*. Por eso cuenta **usos** del PIN y no fichajes: el
     * anti-rebote entra —alguien tecleo su PIN— y el rechazo no, porque un
     * rechazo no dice que nadie se haya quedado sin tarjeta.
     *
     * Se emite **despues** de que la transaccion haya confirmado, por lo mismo
     * que `pin_resets_total`: contarlo dentro sumaria tambien lo que se
     * revirtio, y entonces una subida no significaria nada.
     *
     * El centro se busca aqui y no viaja en el resultado porque es el unico
     * sitio que lo necesita: anadirlo a `RegisterScanResult` habria puesto un
     * identificador de centro al alcance de un `Resource` del quiosco.
     */
    private function countFallback(RegisterScanResult $result): void
    {
        if ($result->isRejected() || $result->employeeUuid === null) {
            return;
        }

        $employee = $this->employees->find($result->employeeUuid);

        if ($employee instanceof EmployeeSnapshot) {
            $this->metrics->pinFallbackScan($employee->siteId);
        }
    }
}
