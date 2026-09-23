<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Job;

use App\Modules\Reporting\Application\Port\QueuedJobFailureMetrics;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El trabajo que genera el fichero de un informe en diferido (**RF-IN-06**).
 *
 * ## Lleva un `uuid` y nada mas
 *
 * Ni el modelo, ni los parametros, ni el alcance. Un trabajo en cola se
 * **serializa** —en Redis, donde se queda hasta que alguien lo recoge— y meter
 * ahi el alcance de una persona o el filtro por empleado los duplicaria en un
 * sitio que nadie audita (regla dura 21). Con el `uuid`, el trabajador relee la
 * fila al arrancar y ve el estado real.
 *
 * ## `$tries = 1`: no se reintenta solo
 *
 * Reintentar es cruzar **otra vez** la plantilla con el calendario sobre la base
 * de datos por la que pasa cada fichaje (ADR-010). Si la primera fallo porque el
 * periodo no cabe en el `statement_timeout` —o porque no hay espacio en disco—,
 * las siguientes fallaran igual, y entre todas habran competido durante minutos
 * con el quiosco de la puerta de personal (RNF-P-02, regla dura 19).
 *
 * El fallo **no se pierde**: la fila queda en `failed` con uno de los cinco
 * codigos de {@see ReportExportFailure}, y eso es lo que la pantalla enseña.
 * Quien lo vea vuelve a pedirlo —con un periodo mas corto, si fue eso— cuando
 * haya arreglado la causa, que es una decision de una persona y no de un
 * planificador.
 *
 * ## LA FILA NUNCA PUEDE QUEDARSE EN `running`, Y AQUI HAY TRES REDES
 *
 * El indice unico parcial solo admite una `pending|running` **por cuenta**: una
 * fila atascada deja a esa persona con `409` hasta que alguien la libere. Asi
 * que:
 *
 * 1. El caso de uso marca `failed` ante cualquier excepcion.
 * 2. {@see self::failed()} lo marca cuando el trabajo muere de una forma que el
 *    caso de uso no puede atrapar: `$timeout` agotado, memoria del trabajador,
 *    `SIGTERM` durante un despliegue.
 * 3. La obsolescencia (`failStale`) lo marca cuando ni siquiera se llega a llamar
 *    a `failed()`: un `SIGKILL`, un `docker compose down` a mitad —que es el paso
 *    1 de cualquier actualizacion— o un servidor que se apaga.
 *
 * Las tres hacen falta y ninguna sobra: cada una cubre un final que las otras no
 * ven.
 *
 * ## El idioma del documento lo fija el trabajo
 *
 * Con el mismo criterio que el middleware `locale.installation` en la descarga
 * sincrona (regla dura 13): un fichero se entrega a un tercero —una gestoria, la
 * herramienta de nomina— y lo abre un programa cuyo idioma no es el del navegador
 * que lo pidio. En la cola no hay navegador ni cabecera `Accept-Language` que
 * negociar, asi que si no se fijara aqui saldria en el idioma por omision del
 * proceso, que es el mismo por casualidad y no por decision.
 *
 * El **correo** de aviso si va en el idioma de la cuenta: eso lo lee una persona
 * concreta y lo decide el notificador.
 *
 * ## Captura y registra en lugar de propagar
 *
 * El estado durable ya esta escrito por el caso de uso antes de que la excepcion
 * llegue aqui. Dejarla salir solo añadiria una fila en `failed_jobs` con la misma
 * informacion y —con la cola `sync`, que es una configuracion legitima de una
 * instalacion pequeña— convertiria el `202` de una peticion que hizo exactamente
 * lo que prometio en un `500`.
 *
 * Se registra con el `uuid` y la **clase** de la excepcion, nunca su mensaje: un
 * error de base de datos puede llevar dentro el valor de una fila (regla dura
 * 21), y el log tecnico viaja al fabricante dentro del paquete de diagnostico.
 *
 * ## `$timeout` generoso a proposito
 *
 * Media hora. Es el doble largo del `statement_timeout` en diferido
 * (`REPORTING_EXPORT_TIMEOUT_SECONDS`, 600 de serie) mas el margen de escribir el
 * fichero y calcular su huella: el que corta la consulta tiene que ser
 * PostgreSQL, que libera la conexion, y no el trabajador, que la dejaria
 * colgando.
 */
final class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Media hora, en segundos. Ver el docblock. */
    public const int TIMEOUT_SECONDS = 1800;

    /** Ver el docblock: no se reintenta solo. */
    public int $tries = 1;

    /** Ver {@see self::TIMEOUT_SECONDS}. */
    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(private readonly string $exportUuid) {}

    public function handle(
        GenerateReportExportHandler $exports,
        LocalePolicyProvider $locales,
        QueuedJobFailureMetrics $metrics,
        Application $app,
        LoggerInterface $logger,
    ): void {
        $startedAt = microtime(true);

        // El documento sale en el idioma de la INSTALACION. Ver el docblock.
        $app->setLocale($locales->current()->default);

        try {
            $export = $exports->handle($this->exportUuid);
        } catch (Throwable $failure) {
            $logger->error('El informe en diferido no se pudo generar.', [
                'report_export_uuid' => $this->exportUuid,
                // La clase, nunca el mensaje. Ver el docblock.
                'failure' => $failure::class,
            ]);

            /*
             * LA METRICA SE INCREMENTA A MANO PORQUE LA EXCEPCION NO SALE.
             *
             * Laravel emite `JobFailed` —y con el se mueve
             * `queue_jobs_failed_total{job}`— solo cuando el trabajo deja salir la
             * excepcion. Aqui se captura a proposito (ver el docblock), y el
             * precio de esa decision era que una generacion fallida no movia
             * ninguna serie: la fila quedaba en `failed` y en el panel de
             * observabilidad no pasaba nada. Es exactamente el caso que la alerta
             * de cola existe para ver.
             *
             * Se escribe **la misma serie y la misma etiqueta** que escribiria el
             * camino normal: dos series para el mismo hecho obligarian a sumarlas
             * en cada consulta.
             */
            $metrics->failed(class_basename(self::class));

            return;
        }

        /*
         * Traza del camino feliz, que es lo que pide la DoD (§10.3,
         * «instrumentacion añadida»). Sin ella, la unica forma de saber cuanto
         * tarda un informe de tres meses en una instalacion real —el dato que
         * decide si el techo sincrono esta bien puesto— seria cronometrarlo a
         * mano.
         *
         * **Sin una sola PII**: identificador, clase de informe, formato,
         * duracion, filas y tamaño. Ni el nombre de quien lo pidio, ni el de nadie
         * que salga dentro, ni la ruta del fichero (regla dura 21).
         */
        $logger->info('Informe en diferido generado.', [
            'report_export_uuid' => $export->uuid,
            'kind' => $export->kind->value,
            'format' => $export->format,
            'status' => $export->status->value,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'row_count' => $export->rowCount,
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
     * Marca `failed` con motivo **cerrado** y **sin el mensaje de la excepcion**:
     * `unexpected`, que es el unico codigo honesto cuando ni siquiera se sabe
     * donde murio. Lo que no puede pasar es que la fila se quede en `running`
     * bloqueando a esa persona.
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

        $logger->error('El trabajo del informe en diferido termino sin poder cerrarse.', [
            'report_export_uuid' => $this->exportUuid,
            'failure' => $exception::class,
        ]);

        try {
            /** @var ReportExportRepository $exports */
            $exports = $container->make(ReportExportRepository::class);
            /** @var Clock $clock */
            $clock = $container->make(Clock::class);

            $export = $exports->findByUuid($this->exportUuid);

            if ($export !== null && $export->isInProgress()) {
                $exports->save($export->fail($clock->now(), ReportExportFailure::Unexpected));
            }

            /*
             * Y SE BORRA LO QUE HUBIERA ESCRITO, que es la parte que no se ve.
             *
             * Si el trabajo muere aqui, la fila **nunca llego a `completed`** y
             * por tanto nunca guardo su `file_path`: en el disco puede haber medio
             * fichero con las horas de la plantilla que ninguna fila menciona, que
             * la purga por caducidad no mira —solo recorre filas `completed`— y
             * que por tanto no borraria nadie.
             *
             * Se borra por `uuid` precisamente porque la ruta es lo que no se
             * sabe. Va despues de cerrar la fila y no antes: lo que no puede
             * quedarse a medias es el estado; el fichero es basura en los dos
             * ordenes. La red de debajo es el barrido de huerfanos de
             * `reporting:purge-expired-exports`, para cuando ni siquiera se llega
             * a ejecutar este metodo.
             */
            $container->make(ReportExportStorage::class)->deleteAllFor($this->exportUuid);
        } catch (Throwable $ignored) {
            $logger->error('Tampoco se pudo marcar el informe en diferido como fallido.', [
                'report_export_uuid' => $this->exportUuid,
                'failure' => $ignored::class,
            ]);
        }
    }
}
