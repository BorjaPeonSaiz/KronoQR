<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Job;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El trabajo que genera el ZIP de la exportacion integra (**RF-PD-14**, RL-20).
 *
 * ## Lleva un `uuid` y nada mas
 *
 * Ni el modelo, ni la fila, ni ningun dato del cliente. Un trabajo en cola se
 * **serializa** —en la base de datos o en Redis, donde se queda hasta que
 * alguien lo recoge— y meter ahi datos de plantilla los duplicaria en un sitio
 * que nadie audita (regla dura 21). Con el `uuid`, el trabajador relee la fila
 * al arrancar y ve el estado real.
 *
 * ## `$tries = 1`: no se reintenta solo
 *
 * Reintentar una exportacion integra es recorrer **otra vez** todas las tablas de
 * la instalacion, incluida aquella por la que pasa cada fichaje (ADR-010). Si la
 * primera fallo por falta de espacio en disco —la causa mas probable con
 * diferencia— las tres siguientes fallaran igual, y entre las cuatro habran
 * competido durante minutos con el quiosco de la puerta de personal.
 *
 * El fallo **no se pierde**: la fila queda en `failed` con uno de los cuatro
 * codigos de {@see DataExportFailure}, y eso es lo que el panel enseña. Quien lo
 * vea vuelve a pulsar el boton cuando haya arreglado la causa, que es una
 * decision de una persona y no de un planificador.
 *
 * ## LA FILA NUNCA PUEDE QUEDARSE EN `running`, Y AQUI HAY TRES REDES
 *
 * El indice unico parcial solo admite una exportacion `pending|running` a la vez:
 * **una fila atascada bloquea RL-20 entero** —`409` eterno en el panel, salida
 * `2` en la consola— hasta que alguien entre por `psql`. Asi que:
 *
 * 1. El caso de uso marca `failed` ante cualquier excepcion, incluida la del
 *    cierre.
 * 2. {@see self::failed()} lo marca cuando el trabajo muere de una forma que el
 *    caso de uso no puede atrapar: `$timeout` agotado, memoria del trabajador,
 *    `SIGTERM` durante un despliegue.
 * 3. La obsolescencia (`failStale`) lo marca cuando ni siquiera se llega a
 *    llamar a `failed()`: un `SIGKILL`, un `docker compose down` a mitad —que es
 *    el paso 1 de cualquier actualizacion— o un servidor que se apaga.
 *
 * Las tres hacen falta y ninguna sobra: cada una cubre un final que las otras no
 * ven.
 *
 * ## Captura y registra en lugar de propagar
 *
 * El estado durable ya esta escrito por el caso de uso antes de que la excepcion
 * llegue aqui. Dejarla salir solo añadiria una fila en `failed_jobs` con la
 * misma informacion y —con la cola `sync`, que es una configuracion legitima de
 * una instalacion pequeña— convertiria el `202` de una peticion que hizo
 * exactamente lo que prometio en un `500`.
 *
 * Se registra con el `uuid` y la **clase** de la excepcion, nunca su mensaje: un
 * error de base de datos puede llevar dentro el valor de una fila (regla dura
 * 21), y el log tecnico viaja al fabricante dentro del paquete de diagnostico.
 * Esa clase es lo unico que se pierde al guardar en la fila un codigo estable en
 * lugar del nombre de una clase de PHP, y por eso se escribe aqui.
 *
 * ## `$timeout` generoso a proposito
 *
 * Una hora. Cuatro años de fichajes de una plantilla grande tardan minutos, no
 * segundos, y un trabajo cortado a la mitad deja la fila ocupando el turno hasta
 * que la obsolescencia la libere.
 */
final class GenerateDataExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Una hora, en segundos.
     *
     * **Es tambien el valor de serie de `PRODUCT_DATA_EXPORT_STALE_AFTER`**, y no
     * por casualidad: el umbral de obsolescencia tiene que ser al menos el tiempo
     * que el trabajo puede tardar legitimamente, o una exportacion lenta se
     * declararia atascada mientras sigue escribiendo. Una prueba ata las dos
     * cifras.
     */
    public const int TIMEOUT_SECONDS = 3600;

    /** Ver el docblock: no se reintenta solo. */
    public int $tries = 1;

    /** Ver {@see self::TIMEOUT_SECONDS}. */
    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(private readonly string $exportUuid) {}

    public function handle(GenerateDataExportHandler $exports, LoggerInterface $logger): void
    {
        $startedAt = microtime(true);

        try {
            $export = $exports->handle($this->exportUuid);
        } catch (Throwable $failure) {
            $logger->error('La exportacion integra no se pudo generar.', [
                'data_export_uuid' => $this->exportUuid,
                // La clase, nunca el mensaje. Ver el docblock.
                'failure' => $failure::class,
            ]);

            return;
        }

        /*
         * Traza del camino feliz, que es lo que pide la DoD (§10.3,
         * «instrumentacion añadida»). Sin ella, la unica forma de saber cuanto
         * tarda una exportacion en una instalacion real —el dato que decide si
         * hay que hacerla por consola— seria cronometrarla a mano.
         *
         * **Sin una sola PII**: identificador, duracion, tamaño y estado. Ni el
         * nombre del fichero de nadie ni la ruta del servidor (regla dura 21).
         */
        $logger->info('Exportacion integra generada.', [
            'data_export_uuid' => $export->uuid,
            'status' => $export->status->value,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'size_bytes' => $export->sizeBytes,
        ]);
    }

    /**
     * El trabajo murio de una forma que el caso de uso no pudo atrapar.
     *
     * `$timeout` agotado, el trabajador sin memoria, un `SIGTERM` durante un
     * despliegue. Laravel llama a este metodo **sin inyeccion de dependencias**
     * —solo recibe la excepcion—, asi que se resuelve del contenedor a mano.
     *
     * `markFailed` es idempotente en la practica: si el caso de uso ya la marco,
     * esto vuelve a escribir el mismo estado final y no cambia nada; lo que no
     * puede pasar es que la fila se quede en `running` bloqueando RL-20.
     *
     * **Envuelto en `try`**: este metodo se ejecuta cuando algo ya ha ido mal, y
     * si la base de datos es justamente lo que fallo, una excepcion aqui
     * sustituiria la causa original por otra en el log. La obsolescencia sigue
     * siendo la red de debajo.
     */
    public function failed(Throwable $exception): void
    {
        $container = Container::getInstance();

        /** @var LoggerInterface $logger */
        $logger = $container->make(LoggerInterface::class);

        $logger->error('El trabajo de exportacion integra termino sin poder cerrarse.', [
            'data_export_uuid' => $this->exportUuid,
            'failure' => $exception::class,
        ]);

        try {
            /** @var DataExportRepository $exports */
            $exports = $container->make(DataExportRepository::class);
            /** @var Clock $clock */
            $clock = $container->make(Clock::class);

            $export = $exports->findByUuid($this->exportUuid);

            if ($export !== null && $export->isInProgress()) {
                $exports->markFailed($export->id, $clock->now(), DataExportFailure::Unexpected);
            }
        } catch (Throwable $ignored) {
            $logger->error('Tampoco se pudo marcar la exportacion integra como fallida.', [
                'data_export_uuid' => $this->exportUuid,
                'failure' => $ignored::class,
            ]);
        }
    }
}
