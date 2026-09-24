<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Domain\ValueObject\ReportExportMaintenance;
use App\Modules\Shared\Application\Port\Clock;

/**
 * El mantenimiento diario de los informes en diferido: **desatasca y purga**
 * (**RF-IN-06**, regla dura 5, decision 3 de la ficha 3.9).
 *
 * ## Por que el fichero caduca
 *
 * Porque contiene **las horas trabajadas de personas identificadas**, en el
 * disco del cliente, y su retencion no puede depender de que alguien se acuerde
 * de borrarlo. Es el mismo criterio que la exportacion integra y que el paquete
 * de diagnostico con datos personales. `REPORTING_EXPORT_RETENTION_DAYS`, 7 de
 * serie; quien lo necesite mas tiempo lo saca del servidor, que es exactamente
 * lo que se espera que haga con el.
 *
 * ## Purgar es borrar el fichero y marcar la fila. NUNCA borrarla
 *
 * La fila se queda para siempre con sus fechas, su huella y su recuento (regla
 * dura 5): «¿salio de aqui un informe con las horas de mi plantilla, cuando y a
 * peticion de quien?» hay que poder contestarlo despues, y una fila borrada no
 * contesta nada. En la lista aparece como `purged`, que es informacion distinta
 * de `failed`.
 *
 * ## Si el fichero ya no esta, se marca igual
 *
 * Alguien pudo borrarlo a mano para hacer sitio. Lo que la fila tiene que
 * reflejar es que **ya no se puede descargar**, y eso es cierto tanto si lo
 * borro esta pasada como si lo borro otro. Lo contrario dejaria filas
 * `completed` para siempre ofreciendo una descarga que responde `404`.
 *
 * ## Primero desatascar y despues purgar, y el orden importa
 *
 * Una fila `running` que nadie va a terminar bloquea a **esa persona**: el
 * indice unico parcial solo admite una `pending|running` por cuenta, asi que su
 * `POST` responde `409` hasta que alguien la libere. Atascarse es facil —el
 * trabajador de cola muere, alguien para los contenedores a mitad, que es el
 * paso 1 de cualquier actualizacion—. Esta pasada es la red que garantiza que se
 * desbloquea solo; la otra mitad la pone {@see RequestReportExport}, que barre
 * antes de intentar crear, porque quien pulsa el boton no tiene por que esperar
 * a mañana.
 *
 * ## Diaria y no horaria
 *
 * Al contrario que la de la exportacion integra. Alli el bloqueo es de la
 * instalacion entera —una fila atascada impide a **todo el mundo** ejercer
 * RL-20— y aqui es de una sola persona, que ademas tiene el barrido de
 * `RequestReportExport` a un clic de distancia. Una pasada horaria sobre una
 * tabla que casi siempre no tiene nada que hacer es trabajo sin lector.
 *
 * ## No publica ningun evento
 *
 * Deliberado, igual que en la exportacion integra: la purga por caducidad **no
 * es un hecho con relevancia legal**, es el vencimiento de un plazo que ya consta
 * en la propia fila (`expires_at`, `purged_at`) y que se anuncio al generarla.
 * Auditar una tarea diaria que casi siempre no hace nada llenaria `audit_log`
 * —solo-apendice y encadenado bajo el candado global de ADR-010— de ruido que
 * compite con el camino por el que pasa cada fichaje.
 */
final readonly class PurgeExpiredReportExports
{
    public function __construct(
        private ReportExportRepository $exports,
        private ReportExportStorage $storage,
        private Clock $clock,
        /**
         * Segundos tras los cuales un informe sin terminar se declara atascado.
         * Ya resuelto por quien construye (regla dura 14).
         */
        private int $staleAfterSeconds,
    ) {}

    public function handle(): ReportExportMaintenance
    {
        $now = $this->clock->now();

        $released = $this->exports->failStale(
            $now->modify('-'.max(1, $this->staleAfterSeconds).' seconds'),
            $now,
        );

        $purged = 0;

        foreach ($this->exports->expired($now) as $export) {
            if ($export->filePath !== null) {
                $this->storage->delete($export->filePath);
            }

            $this->exports->save($export->purge($now));
            $purged++;
        }

        return new ReportExportMaintenance($purged, $released, $this->sweepOrphans());
    }

    /**
     * Borra lo que hay en el disco **y ninguna fila menciona**.
     *
     * ## El fichero que nadie sabe que existe
     *
     * La ruta solo se guarda al cerrar la fila. Si el trabajo muere antes de una
     * forma que no puede atrapar —`$timeout` agotado, el trabajador sin memoria,
     * un `SIGTERM` durante un despliegue, un `docker compose down` a mitad—, en
     * el disco queda **media plantilla con sus horas** y en la base de datos una
     * fila `failed` sin `file_path`. La purga por caducidad no lo ve: solo recorre
     * filas `completed`. Nadie lo borraria nunca.
     *
     * Asi que se recorre al reves: lo que hay escrito menos lo que tiene derecho a
     * estar escrito —las filas `completed` sin purgar— es basura, y se borra.
     *
     * ## Por que no se puede hacer al reves ni por fecha
     *
     * Por fecha seria una carrera: un fichero recien creado por un trabajo **en
     * curso** parece huerfano hasta que la fila se cierra. La lista de `uuid` con
     * derecho a fichero se lee **despues** de purgar y antes de mirar el disco, y
     * aun asi un trabajo que termine justo en medio veria su directorio borrado
     * — por eso {@see ReportExportRepository::uuidsWithFile()}
     * incluye solo `completed`, y el barrido corre una vez al dia de madrugada,
     * cuando no hay nadie generando.
     *
     * Es una red de seguridad, no el camino normal: el camino normal es el
     * `failed()` del trabajo, que borra su propio directorio.
     */
    private function sweepOrphans(): int
    {
        $entitled = $this->exports->uuidsWithFile();
        $deleted = 0;

        foreach ($this->storage->storedUuids() as $uuid) {
            if (\in_array($uuid, $entitled, true)) {
                continue;
            }

            $this->storage->deleteAllFor($uuid);
            $deleted++;
        }

        return $deleted;
    }
}
