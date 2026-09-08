<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Domain\ValueObject\DataExportMaintenance;
use App\Modules\Shared\Application\Port\Clock;

/**
 * El mantenimiento horario de la exportacion integra: **desatasca y purga**
 * (**RF-PD-14**, RL-20, regla dura 5).
 *
 * ## Por que la exportacion caduca
 *
 * Porque el fichero es **una copia completa de la plantilla y de cuatro años de
 * fichajes**, en el disco del cliente, con permisos `0600` y sin nadie que se
 * acuerde de el. Es el mismo criterio que el paquete de diagnostico con datos
 * personales: la retencion de un fichero asi no puede depender de que alguien se
 * acuerde de borrarlo. `PRODUCT_DATA_EXPORT_RETENTION_DAYS`, 7 de serie; quien
 * lo quiera guardar mas tiempo lo saca del servidor, que es exactamente lo que
 * se espera que haga con el.
 *
 * ## Purgar es borrar el fichero y marcar la fila. NUNCA borrarla
 *
 * La fila se queda para siempre con sus fechas, sus recuentos y su huella
 * (regla dura 5): «¿salio de aqui una copia completa de mis datos, y cuando?»
 * hay que poder contestarlo años despues, y una fila borrada no contesta nada.
 * En la lista aparece como `purged`, que es informacion distinta de `failed`.
 *
 * ## Si el fichero ya no esta, se marca igual
 *
 * Alguien pudo borrarlo a mano para hacer sitio. Lo que la fila tiene que
 * reflejar es que **ya no se puede descargar**, y eso es cierto tanto si lo
 * borro esta pasada como si lo borro otro. Lo contrario dejaria filas
 * `completed` para siempre, ofreciendo una descarga que devuelve `404`.
 *
 * ## No publica ningun evento
 *
 * Y es deliberado: la purga por caducidad **no es un hecho con relevancia
 * legal**, es el vencimiento de un plazo que ya consta en la propia fila
 * (`expires_at`, `purged_at`) y que se anuncio al generarla. Auditar una tarea
 * horaria que casi siempre no hace nada llenaria `audit_log` —solo-apendice,
 * encadenado bajo el candado global de ADR-010— de ruido que compite con el
 * camino por el que pasa cada fichaje.
 */
final readonly class PurgeExpiredDataExportsHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private DataExportArchiveWriter $writer,
        private Clock $clock,
        /**
         * Segundos tras los cuales una exportacion sin terminar se declara
         * atascada. Ya resuelto por quien construye (regla dura 14): el caso de
         * uso no consulta la configuracion.
         */
        private int $staleAfterSeconds,
    ) {}

    public function handle(): DataExportMaintenance
    {
        $now = $this->clock->now();

        /*
         * PRIMERO DESATASCAR Y DESPUES PURGAR, y el orden importa.
         *
         * Una fila `running` que nadie va a terminar bloquea la exportacion
         * integra entera: el indice unico parcial solo deja una `pending|running`
         * a la vez, asi que el `POST` responde `409` para siempre y
         * `product:export-all` sale `2`. Y atascarse es facil —el trabajador de
         * cola muere, alguien para los contenedores a mitad, que es el paso 1 de
         * cualquier actualizacion—.
         *
         * Esta pasada corre **cada hora**, asi que es la red que garantiza que
         * RL-20 vuelve sola sin que nadie entre por `psql`. La otra mitad la pone
         * `RequestDataExportHandler`, que barre antes de intentar crear: quien
         * pulsa el boton no tiene por que esperar hasta la hora en punto.
         */
        $released = $this->exports->failStale(
            $now->modify('-'.max(1, $this->staleAfterSeconds).' seconds'),
            $now,
        );

        $purged = 0;

        foreach ($this->exports->expired($now) as $export) {
            if ($export->filePath !== null) {
                $this->writer->delete($export->filePath);
            }

            $this->exports->markPurged($export->id, $now);
            $purged++;
        }

        return new DataExportMaintenance($purged, $released);
    }
}
