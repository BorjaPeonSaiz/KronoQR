<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Audit;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El asiento que se prepara dentro de la peticion y se escribe **al terminarla**
 * (ADR-039).
 *
 * ## Para que existe, con un solo caso de uso hoy
 *
 * `auth.lockout_started` es el unico hecho auditable del producto que **provoca
 * quien ataca**. Escribirlo en linea, dentro de la respuesta, tenia dos efectos
 * que ADR-039 documenta y que ninguno se podia quedar:
 *
 * 1. **Un oraculo de tiempo** (RS-03, regla dura 17). Solo el intento que abre el
 *    bloqueo pagaba una transaccion y el `pg_advisory_xact_lock` global de
 *    ADR-010; los demas rechazos, no. Decenas de milisegundos de diferencia son
 *    medibles desde fuera.
 * 2. **Un rechazo convertido en `500`.** La excepcion de un `audit_log` averiado
 *    subia por el camino de `/scan/pin` y dejaba a alguien sin fichar por un
 *    problema de auditoria, que es exactamente lo que prohibe la regla dura 19.
 *
 * ## Lo que se resuelve antes y lo que se aplaza
 *
 * **El comando se construye entero antes de responder**: actor, sujeto, payload,
 * IP, `User-Agent` e instante. Todos ellos son propiedades de la peticion en
 * curso, y despues de responder ya no existen —`CurrentAuditContext` diria
 * `system` y ninguna IP—. Lo unico que se aplaza es la escritura.
 *
 * ## Despues de responder, y no en cola
 *
 * Aunque la instalacion tenga Horizon. Una cola introduce un trabajador del que
 * pasa a depender que el asiento **llegue a existir**, y un asiento perdido
 * porque nadie levanto el *worker* es peor que uno escrito cinco milisegundos
 * tarde. El dia que las cifras pidan encolarlo, cabe entero detras de esta clase
 * sin tocar a ningun llamante.
 *
 * En consola y en las colas no hay respuesta que enviar y los `terminating` se
 * ejecutan al acabar el proceso: el asiento se escribe igual. En la suite lo
 * ejecuta el `terminate()` que el kernel de pruebas llama al final de cada
 * peticion, asi que una prueba que pase por HTTP ve el asiento y una que invoque
 * el caso de uso en linea no lo ve — y eso ultimo es correcto, no un defecto.
 *
 * ## Si la escritura falla, se queda en un error tecnico
 *
 * Y **nunca sube**. Es la unica cesion de la regla dura 6 que hay en el producto,
 * y ADR-039 explica por que se acepta aqui y solo aqui: el hecho que se pierde lo
 * provoca quien ataca, y conservarlo a costa de un `500` en el camino de fichaje
 * seria el intercambio que la regla dura 19 prohibe. El `warning`
 * `auth.lockout_started` del log tecnico y el contador siguen ahi.
 *
 * **Del fallo se registra la clase de la excepcion y no su mensaje** (regla dura
 * 21). El mensaje de una `QueryException` de Laravel lleva el SQL con sus
 * valores enlazados, y desde ADR-039 uno de esos valores es la **IP en claro**
 * del origen: escribirlo en el log tecnico la mandaria al paquete de diagnostico
 * del fabricante (ADR-020) por la puerta de atras. La clase, la accion y el
 * `trace_id` bastan para saber que se rompio y correlacionarlo con la traza.
 */
final readonly class DeferredAuditEntry
{
    public function __construct(
        private Application $app,
        private RecordAuditEntry $audit,
        private LoggerInterface $logger,
    ) {}

    /**
     * Aplaza el asiento hasta que la peticion en curso haya terminado.
     *
     * El `trace_id` se captura **ahora**: al ejecutarse el `terminating`, el span
     * de la peticion ya se cerro y leerlo alli daria otra respuesta o ninguna.
     */
    public function afterResponse(RecordAuditEntryCommand $command): void
    {
        $traceId = SpanScope::currentTraceId();

        // **Una sola vez, aunque el contenedor reproduzca la lista.**
        // `Application::terminate()` recorre sus `terminating` y no los vacia:
        // en PHP-FPM da igual —un proceso, una peticion— y en un contenedor que
        // sirve varias con la misma instancia, no. Un asiento duplicado
        // rompe una cadena de hash que no se puede reescribir, asi que el guardia
        // se pone aqui y no en quien llama.
        $pending = true;

        $this->app->terminating(function () use ($command, $traceId, &$pending): void {
            if (! $pending) {
                return;
            }

            $pending = false;

            $this->write($command, $traceId);
        });
    }

    private function write(RecordAuditEntryCommand $command, ?string $traceId): void
    {
        try {
            // `DatabaseAuditTrail::append()` abre su propia transaccion cuando no
            // hay ninguna, que es el caso aqui: el candado consultivo de ADR-010
            // es *de transaccion* y necesita una para sostenerse.
            $this->audit->handle($command);
        } catch (Throwable $failure) {
            $this->logger->error('audit.deferred_entry_failed', [
                'trace_id' => $traceId,
                'action' => $command->action->value,
                // Ver el docblock: la clase, no el mensaje.
                'exception' => $failure::class,
            ]);
        }
    }
}
