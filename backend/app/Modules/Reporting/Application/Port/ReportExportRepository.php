<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Exception\ReportExportAlreadyInProgress;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use DateTimeImmutable;

/**
 * La tabla `report_exports` (**RF-IN-06**, decision 1 de la ficha 3.9).
 *
 * ## La exclusion mutua la resuelve el indice, no un `SELECT`
 *
 * {@see self::create()} inserta y **traduce** el choque contra el indice unico
 * parcial por solicitante en {@see ReportExportAlreadyInProgress}. Comprobar
 * antes con una consulta dejaria pasar las dos pulsaciones de dos pestañas
 * abiertas a la vez, que es exactamente el caso que la restriccion existe para
 * cerrar (regla dura 8 aplicada a algo que no es un fichaje: la garantia la da
 * la base de datos, no PHP).
 *
 * ## Solo el solicitante ve lo suyo, y eso se aplica en la consulta
 *
 * No hay ningun metodo que devuelva «todas»: {@see self::recentFor()} y
 * {@see self::findByUuidFor()} exigen el identificador de la cuenta. Un
 * `findByUuid()` sin dueño seria la via por la que un `admin` acabaria viendo
 * las exportaciones de los demas, que es justamente lo que la decision 2 deja
 * fuera de alcance. Las dos excepciones —{@see self::findByUuid()} y
 * {@see self::expired()}— son del trabajador de cola y de la purga, que no
 * actuan en nombre de nadie.
 *
 * ## La lectura NO es tolerante
 *
 * Si algo va mal al leer, tiene que verse: una lista que fallara en silencio
 * seria un informe con las horas de la plantilla que existe en el disco y no
 * aparece en ninguna pantalla.
 */
interface ReportExportRepository
{
    /**
     * Crea la fila en `pending`.
     *
     * @param  list<string>  $criteria  Los criterios de inclusion ya traducidos.
     *
     * @throws ReportExportAlreadyInProgress si esa cuenta ya tiene una `pending` o `running`
     */
    public function create(
        string $uuid,
        ReportExportKind $kind,
        string $format,
        ReportExportParameters $parameters,
        AccessScope $scope,
        array $criteria,
        int $requestedByUserId,
        DateTimeImmutable $requestedAt,
    ): ReportExport;

    /**
     * La fila por su identificador publico, **sin comprobar dueño**.
     *
     * Solo para el trabajador de cola y la descarga por token, que no actuan en
     * nombre de una sesion. Todo lo que venga de una peticion autenticada usa
     * {@see self::findByUuidFor()}.
     */
    public function findByUuid(string $uuid): ?ReportExport;

    /**
     * Igual que la anterior pero **bloqueando la fila** (`FOR UPDATE`).
     *
     * Es lo que hace que el enlace sea de un solo uso de verdad: dos peticiones
     * simultaneas con el mismo token se serializan aqui, y la segunda lee la fila
     * con el token ya consumido. Sin el bloqueo, las dos verian el token vigente
     * y las dos entregarian el fichero contando una sola descarga.
     */
    public function lockByUuid(string $uuid): ?ReportExport;

    /**
     * La fila por su identificador publico **y su dueño**.
     *
     * Devuelve nulo para la exportacion de otra persona, que es lo que produce el
     * `404` del contrato: `403` confirmaria que existe (decision 2).
     */
    public function findByUuidFor(string $uuid, int $requestedByUserId): ?ReportExport;

    /**
     * Igual que la anterior pero **bloqueando la fila** (`FOR UPDATE`).
     *
     * La usa la emision del enlace de descarga, que es una escritura disfrazada
     * de `GET`. Sin el bloqueo, esa escritura lee la fila, tarda unos
     * milisegundos en acuñar el token y vuelve a guardarla entera: una descarga
     * o la purga que ocurrieran en ese hueco perderian su `download_count` —o,
     * peor, resucitarian el `file_path` de un fichero recien borrado—.
     *
     * El dueño entra en la MISMA consulta que el bloqueo y no en una previa: dos
     * consultas dejarian una ventana entre comprobar de quien es y bloquearla.
     */
    public function lockByUuidFor(string $uuid, int $requestedByUserId): ?ReportExport;

    /** La que ocupa el turno de esa cuenta, para componer el cuerpo del `409`. */
    public function inProgressFor(int $requestedByUserId): ?ReportExport;

    /**
     * Las mas recientes **de esa cuenta**, de la mas nueva a la mas antigua.
     *
     * @return list<ReportExport>
     */
    public function recentFor(int $requestedByUserId, int $limit): array;

    /**
     * Persiste el resultado de una transicion del modelo.
     *
     * Un solo metodo y no ocho (`markRunning`, `markCompleted`, …) porque las
     * transiciones ya viven en {@see ReportExport} y son las que comprueban de
     * donde vienen: repetir aqui un metodo por estado daria dos sitios donde
     * decidir lo mismo. Actualiza **solo las columnas mutables**; `uuid`, `kind`,
     * `parameters`, `scope` y el solicitante no se tocan nunca.
     */
    public function save(ReportExport $export): void;

    /**
     * Sella por donde se aviso, y **solo eso**.
     *
     * Metodo propio en lugar de `save()` porque el aviso ocurre **fuera de
     * cualquier transaccion y despues de cerrar la fila** —un SMTP lento no puede
     * bloquear una fila—, asi que en ese hueco pueden haber pasado cosas: alguien
     * pidio el enlace, se lo descargo, o la purga borro el fichero. Un `save()` de
     * diecinueve columnas con la instancia de antes del correo las desharia todas.
     *
     * Tres columnas y ningun estado: avisar no es una transicion.
     */
    public function recordNotification(
        int $id,
        DateTimeImmutable $notifiedAt,
        ReportExportNotificationChannel $channel,
    ): void;

    /**
     * Los `uuid` de las exportaciones que **todavia tienen derecho a un fichero**
     * en el disco: `completed` y sin purgar.
     *
     * Existe para la limpieza de huerfanos de `PurgeExpiredReportExports`
     * —nombrado en prosa porque un `use` de un caso de uso desde un puerto es la
     * frontera que Deptrac rechaza—:
     * todo lo demas que haya en `REPORTING_EXPORT_PATH` es basura de una
     * generacion que murio sin poder cerrarse —`$timeout` agotado, el trabajador
     * sin memoria, un `SIGTERM`—, y esa basura son las horas de la plantilla
     * escritas a medias en un fichero que ninguna fila menciona.
     *
     * @return list<string>
     */
    public function uuidsWithFile(): array;

    /**
     * Marca como `failed` con motivo `stale` las que llevan demasiado tiempo sin
     * terminar.
     *
     * Devuelve cuantas ha liberado. El umbral entra ya resuelto (regla dura 14):
     * el caso de uso no consulta la configuracion.
     */
    public function failStale(DateTimeImmutable $staleBefore, DateTimeImmutable $now): int;

    /**
     * Las `completed` cuyo `expires_at` ya paso y cuyo fichero sigue sin purgar.
     *
     * @return list<ReportExport>
     */
    public function expired(DateTimeImmutable $now): array;
}
