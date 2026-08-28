<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\ClientAddressPseudonym;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Compliance\Infrastructure\Audit\DeferredAuditEntry;
use App\Modules\Compliance\Infrastructure\Audit\ManagementUserDirectory;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * El rastro de la autenticacion, repartido entre `audit_log` y el log tecnico
 * **segun ADR-039** (OWASP A09, RS-05, RS-07, RS-12, regla dura 6, ADR-010).
 *
 * **Es la arista de ADR-025 de este modulo**, la misma que
 * {@see AuditedPersonalDataAccessLog}: el puerto lo declaran quienes lo
 * necesitan —`Identity` en el panel y el portal, `Workforce` en el verificador
 * del PIN y `Attendance` en el fichaje de respaldo— en `Shared/Application/Port`,
 * y lo implementa `Compliance`, que es el unico que puede tocar la cadena de
 * hash. Ninguno de los cuatro importa `Compliance`.
 *
 * ## Que va a cada almacen
 *
 * ```
 * auth.login_succeeded   audit_log (solo panel) + log + contador
 * auth.logout            audit_log (solo panel)
 * auth.lockout_started   audit_log AFTER RESPONSE + log + contador
 * auth.login_failed      log + contador                 NUNCA audit_log
 * ```
 *
 * La tabla completa, con el motivo de cada casilla —el candado global de
 * ADR-010, el hueco de `actor_type=employee` de ADR-037 y el oraculo de tiempo
 * de RS-03—, esta en `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`.
 * Aqui solo se ejecuta.
 *
 * ## El bloqueo se prepara aqui y se escribe al terminar la peticion
 *
 * {@see DeferredAuditEntry}, y el porque esta entero en su docblock: es el unico
 * asiento que provoca quien ataca, asi que ni puede costar distinto en el flanco
 * ni puede convertir un rechazo en un `500`. El comando se construye **antes** de
 * responder —el actor, la IP y el instante son de la peticion en curso— y solo se
 * aplaza la escritura.
 *
 * El exito y el cierre del panel siguen siendo sincronos y dentro de la
 * transaccion de quien llama: ahi la garantia de la regla dura 6 si se quiere.
 *
 * ## El actor, caso por caso
 *
 * - **Entrar y salir del panel**: la propia cuenta, con su `users.id` resuelto
 *   por {@see ManagementUserDirectory}. Durante el acceso todavia no hay sesion,
 *   asi que {@see CurrentAuditContext} no puede saberlo: diria `system`, que en
 *   un asiento de inicio de sesion seria falso.
 * - **Bloqueos**: el actor de la peticion en curso. En el quiosco eso es el
 *   propio dispositivo emparejado —`device#id`, que ademas dice desde que tablet
 *   se tecleaba—; en el panel y en el portal, `system`, y aqui si es la verdad:
 *   **el bloqueo lo decide el servidor**, no la persona que fallo.
 *
 * ## Lo que el payload lleva y lo que no
 *
 * `channel`, el UUID del sujeto cuando se conoce y —en el bloqueo— su duracion.
 * **Ni correo, ni codigo de empleado, ni nombre, ni contrasena, ni PIN** (regla
 * dura 21). El origen va en las columnas `ip` y `user_agent`, como en los otros
 * cinco escritores de la tabla (ADR-039): un seudonimo en el payload de tres
 * acciones y la direccion en claro en las otras veinte obligaria a saber de que
 * accion viene cada fila para saber que significa su origen.
 * {@see ClientAddressPseudonym} sigue existiendo, y solo para el log tecnico,
 * que es el que viaja al fabricante.
 *
 * ## Medir no puede impedir entrar
 *
 * El log y el contador van envueltos: si el `logger` o Redis fallan, quien
 * tecleo bien su contrasena entra igual.
 */
final readonly class AuditedAuthenticationJournal implements AuthenticationJournal
{
    public function __construct(
        private RecordAuditEntry $audit,
        private DeferredAuditEntry $deferred,
        private CurrentAuditContext $context,
        private ManagementUserDirectory $users,
        private ClientAddressPseudonym $addresses,
        private AuthenticationMetrics $metrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function succeeded(AuthChannel $channel, string $subjectUuid): void
    {
        if ($channel->sessionEventsAreAudited()) {
            $this->audit->handle($this->entryFor(AuditAction::LoginSucceeded, $channel, $subjectUuid));
        }

        // En los tres canales, y con sujeto: es la unica linea del log tecnico
        // que permite responder «¿alguien acerto DESPUES de la racha de fallos?»
        // sin salir del log —que es la pregunta del runbook de credenciales §6—.
        $this->write('auth.login_succeeded', $channel, $subjectUuid, [], LogLevel::INFO);

        $this->count($channel, AuthOutcome::SUCCESS);
    }

    public function failed(
        AuthChannel $channel,
        ?string $subjectUuid,
        AuthFailureReason $reason,
    ): void {
        // Un solo apunte, con los mismos campos y el mismo coste, salga de donde
        // salga el rechazo: es la mitad de RS-03 que depende de este fichero.
        $this->write('auth.login_failed', $channel, $subjectUuid, [
            'reason' => $reason->value,
        ]);

        // Tambien cuando el bloqueo ya estaba puesto: es un intento que no acabo
        // en sesion. `lockout` cuenta bloqueos ABIERTOS y no intentos
        // rechazados; ver AuthOutcome, donde esta escrito por que la alerta lo
        // necesita asi.
        $this->count($channel, AuthOutcome::FAILURE);
    }

    public function lockoutStarted(
        AuthChannel $channel,
        ?string $subjectUuid,
        int $lockSeconds,
    ): void {
        $seconds = max(0, $lockSeconds);

        // Preparado ahora —con el actor y el origen de esta peticion—, escrito
        // al terminarla. Ver DeferredAuditEntry y ADR-039.
        $this->deferred->afterResponse($this->entryFor(
            AuditAction::LockoutStarted,
            $channel,
            $subjectUuid,
            ['lock_seconds' => $seconds],
        ));

        $this->write('auth.lockout_started', $channel, $subjectUuid, [
            'lock_seconds' => $seconds,
        ]);

        // Uno por bloqueo abierto, que es exactamente lo que
        // `KronoqrAuthLockouts` cuenta para reconocer credential stuffing.
        $this->count($channel, AuthOutcome::LOCKOUT);
    }

    public function loggedOut(AuthChannel $channel, ?string $subjectUuid): void
    {
        if (! $channel->sessionEventsAreAudited() || $subjectUuid === null) {
            return;
        }

        $this->audit->handle($this->entryFor(AuditAction::Logout, $channel, $subjectUuid));
    }

    /**
     * El asiento, resuelto entero contra la peticion en curso.
     *
     * Se construye y no se escribe: quien lo pide decide si va dentro de la
     * transaccion o despues de responder. Todo lo que depende de la peticion
     * —actor, IP, cliente, instante— queda fijado aqui, porque despues de
     * responder ya no existe.
     *
     * @param  array<string, scalar>  $extra
     */
    private function entryFor(
        AuditAction $action,
        AuthChannel $channel,
        ?string $subjectUuid,
        array $extra = [],
    ): RecordAuditEntryCommand {
        $payload = ['channel' => $channel->value, ...$extra];

        if ($subjectUuid !== null) {
            $payload[$channel->subjectType().'_uuid'] = $subjectUuid;
        }

        return new RecordAuditEntryCommand(
            actor: $this->actorFor($action, $channel, $subjectUuid),
            action: $action,
            subject: $this->subjectFor($channel, $subjectUuid),
            payload: AuditPayload::of($payload),
            // El instante del hecho, no el de la escritura: el asiento del
            // bloqueo se escribe despues de responder y sin esto se fecharia
            // unos milisegundos tarde (regla dura 9, ADR-021).
            occurredAt: $this->clock->now(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        );
    }

    /**
     * Quien lo hizo. Ver el docblock: la cuenta cuando entra o sale del panel, y
     * el actor de la peticion —`device` en el quiosco, `system` en el resto—
     * cuando lo que se registra es una decision del servidor.
     */
    private function actorFor(AuditAction $action, AuthChannel $channel, ?string $subjectUuid): AuditActor
    {
        if ($action === AuditAction::LockoutStarted || $channel !== AuthChannel::MANAGEMENT) {
            return $this->context->actor();
        }

        $id = $this->users->idOf($subjectUuid);

        return $id === null ? $this->context->actor() : AuditActor::user($id);
    }

    /**
     * Sobre quien recae.
     *
     * El sujeto de una cuenta de gestion lleva su `users.id`, que es lo que hace
     * util el indice `(subject_type, subject_id)` para responder que paso con
     * esta cuenta. El de un empleado va sin identificador y con su UUID en el
     * payload: resolverlo obligaria a `Compliance` a consultar la tabla de otro
     * modulo por un dato que el indice GIN del payload ya deja buscar.
     */
    private function subjectFor(AuthChannel $channel, ?string $subjectUuid): AuditSubject
    {
        if ($channel !== AuthChannel::MANAGEMENT) {
            return AuditSubject::of($channel->subjectType());
        }

        return AuditSubject::of('user', $this->users->idOf($subjectUuid));
    }

    /**
     * El apunte del log tecnico. Envuelto: observar no puede romper el acceso.
     *
     * **Aqui si va `ip_hash` y nunca la direccion en claro**: este log viaja al
     * fabricante dentro del paquete de diagnostico (ADR-020) y una IP de la red
     * interna de un hotel, junto a la hora, dice desde que puesto se trabajo. Es
     * ademas la clave con la que el runbook de credenciales une una racha de
     * fallos con el acceso que vino despues.
     *
     * @param  array<string, scalar>  $extra
     * @param  LogLevel::*  $level
     */
    private function write(
        string $message,
        AuthChannel $channel,
        ?string $subjectUuid,
        array $extra,
        string $level = LogLevel::WARNING,
    ): void {
        try {
            $this->logger->log($level, $message, [
                'trace_id' => SpanScope::currentTraceId(),
                'channel' => $channel->value,
                // Siempre presente, aunque sea nulo: una clave que aparece y
                // desaparece obliga a quien consulta el log a escribir dos
                // consultas para la misma pregunta.
                'subject_uuid' => $subjectUuid,
                'ip_hash' => $this->addresses->of($this->context->ip()),
                ...$extra,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo.
        }
    }

    private function count(AuthChannel $channel, AuthOutcome $outcome): void
    {
        try {
            $this->metrics->attempt($channel, $outcome);
        } catch (Throwable) {
            // El adaptador de metricas ya traga lo suyo; esto cubre ademas el
            // caso de que ni siquiera se pueda resolver.
        }
    }
}
