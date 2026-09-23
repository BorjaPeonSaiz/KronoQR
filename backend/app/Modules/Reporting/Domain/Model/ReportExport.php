<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Model;

use App\Modules\Reporting\Domain\Exception\InvalidReportExportTransition;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use DateTimeImmutable;

/**
 * Un informe generado en diferido (**RF-IN-06**, ADR-041, ficha 3.9).
 *
 * ## Es la fila de `report_exports`, contada en el lenguaje del dominio
 *
 * Y es tambien el **sujeto de la policy**: `Gate::policy(ReportExport::class,
 * ReportExportPolicy::class)`. Se registra contra este modelo y no contra una
 * fila de Eloquent por lo mismo que el informe sincrono: la autorizacion se
 * decide **antes** de tocar la base de datos.
 *
 * ## Sin reloj propio (regla dura 2)
 *
 * Ningun metodo lee la hora. `expired()`, `start()`, `complete()` y los dos del
 * enlace reciben el instante de quien lo tiene resuelto por el puerto `Clock`.
 * Sin eso, la prueba de que el enlace caduca a los quince minutos tendria que
 * esperar quince minutos.
 *
 * ## Sin generar secretos (regla dura 1, y algo mas)
 *
 * {@see self::issueDownloadToken()} recibe el **hash ya calculado**, no el
 * token. El dominio no llama a `random_bytes()` ni a `hash()`: no porque no
 * pueda —son funciones de PHP— sino porque el secreto tiene que existir en un
 * solo sitio, el borde que lo devuelve en la URL, y lo que llega aqui es lo
 * unico que la fila puede guardar. Si el dominio lo generara, tendria que
 * devolverlo para que alguien lo enseñara, y un objeto de dominio que devuelve
 * secretos es un objeto de dominio del que hay que acordarse de no registrar en
 * los logs.
 *
 * ## Las transiciones devuelven otra instancia
 *
 * `readonly` entero, como el resto de modelos de este producto. Quien persiste
 * es el repositorio, que recibe el resultado; el modelo no sabe que hay una base
 * de datos. Cada transicion **comprueba de donde viene**: `complete()` sobre una
 * fila ya fallida lanza {@see InvalidReportExportTransition} en vez de
 * resucitarla en silencio, que es como una exportacion que el trabajador dio por
 * muerta acabaria apareciendo como descargable.
 *
 * ## Los campos del fichero son nulos hasta que termina
 *
 * `fileName`, `filePath`, `sha256`, `sizeBytes`, `rowCount` y `expiresAt` solo
 * tienen valor en `completed` (y siguen tras `purged`, salvo `filePath`, que se
 * limpia al borrar el fichero para que nadie intente servirlo). El `CHECK`
 * `report_exports_chk_completed_is_complete` es la mitad que lo garantiza en la
 * base de datos.
 */
final readonly class ReportExport
{
    /**
     * @param  list<string>  $criteria  Los criterios de inclusion **ya traducidos** al idioma
     *                                  del solicitante. Viajan en la fila porque el fichero de
     *                                  nomina no los lleva dentro (decision 5 de la ficha): una
     *                                  linea de comentario rompe la importacion del programa de
     *                                  nomina.
     */
    public function __construct(
        /** Clave interna. Nunca sale de la base de datos (doc 01 §5.5). */
        public int $id,
        /** Identificador publico: el de la URL y el de la respuesta. */
        public string $uuid,
        public ReportExportKind $kind,
        /** `csv`, `xlsx` o `pdf`, ya acotado por {@see ReportExportKind::allows()}. */
        public string $format,
        public ReportExportStatus $status,
        public ReportExportParameters $parameters,
        /**
         * El alcance del solicitante **en el momento de pedirlo**.
         *
         * Instantanea, no una lectura viva: el trabajo la aplica tal cual y nunca
         * la recalcula (decision 1). Ver {@see ReportExportParameters}.
         *
         * **Nulo cuando la fila esta `purged`** (RL-11): la lista de
         * departamentos que alguien alcanzaba es un dato sobre esa persona, y una
         * vez borrado el fichero deja de tener uso operativo. El hecho completo
         * sigue en `audit_log` (`report_export.requested`), que se conserva cuatro
         * años (RL-02). Ver {@see self::purge()}.
         */
        public ?AccessScope $scope,
        public int $requestedByUserId,
        public ?string $requestedByUuid,
        public ?string $requestedByName,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $failedAt,
        public ?ReportExportFailure $failureReason,
        public ?string $filePath,
        public ?string $fileName,
        public ?int $sizeBytes,
        public ?string $sha256,
        public ?int $rowCount,
        public array $criteria,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $purgedAt,
        /** SHA-256 del token vigente. **Nunca el token.** */
        public ?string $downloadTokenHash,
        public ?DateTimeImmutable $downloadTokenExpiresAt,
        public ?DateTimeImmutable $downloadedAt,
        public int $downloadCount,
        public ?DateTimeImmutable $notifiedAt,
        public ?ReportExportNotificationChannel $notificationChannel,
    ) {}

    /** Sigue ocupando el turno de esa persona: un segundo `POST` suyo recibe `409`. */
    public function isInProgress(): bool
    {
        return $this->status->isInProgress();
    }

    /**
     * Hay fichero que entregar.
     *
     * Las tres condiciones y no una: `completed` dice que la generacion termino,
     * `filePath` dice que la fila sabe donde esta y `purgedAt` nulo dice que la
     * purga no lo ha borrado. Se separan porque una limpieza manual —alguien que
     * vacia `storage/app/reports` para hacer sitio— deja la fila `completed` con
     * un fichero que ya no existe, y ese caso responde `404` en vez de romper.
     */
    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable() && $this->filePath !== null && $this->purgedAt === null;
    }

    /**
     * El fichero ya deberia haberse borrado.
     *
     * `<=` y no `<`: en el instante exacto de `expires_at` el informe ya caduco.
     * Es el lado seguro —un fichero con horas nominales de la plantilla se borra
     * antes, no despues—.
     */
    public function expired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * Se puede emitir un enlace de descarga para este informe.
     *
     * Es `isDownloadable()` **y ademas** que el fichero no haya caducado. Los dos
     * porque la purga corre una vez al dia: entre el vencimiento y la pasada hay
     * una ventana en la que la fila sigue `completed` con su fichero en el disco,
     * y durante esa ventana el enlace no se emite. La caducidad es una promesa al
     * cliente, no un efecto secundario de cuando pase el planificador.
     */
    public function canIssueDownloadLink(DateTimeImmutable $now): bool
    {
        return $this->isDownloadable() && ! $this->expired($now);
    }

    /** `pending` → `running`. */
    public function start(DateTimeImmutable $startedAt): self
    {
        $this->assertStatusIs(ReportExportStatus::Pending, 'arrancar');

        return $this->withLifecycle(status: ReportExportStatus::Running, startedAt: $startedAt);
    }

    /**
     * `running` → `completed`, con todo lo que el `CHECK` exige a la vez.
     *
     * @param  list<string>  $criteria
     */
    public function complete(
        DateTimeImmutable $completedAt,
        string $filePath,
        string $fileName,
        int $sizeBytes,
        string $sha256,
        int $rowCount,
        array $criteria,
        DateTimeImmutable $expiresAt,
    ): self {
        $this->assertStatusIs(ReportExportStatus::Running, 'dar por terminado');

        return $this->withFile(
            completedAt: $completedAt,
            filePath: $filePath,
            fileName: $fileName,
            sizeBytes: $sizeBytes,
            sha256: $sha256,
            rowCount: $rowCount,
            criteria: $criteria,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Cualquiera de los dos estados en curso → `failed`.
     *
     * Admite venir de `pending` ademas de `running` porque la obsolescencia marca
     * tambien las que nadie llego a recoger: la cola estaba parada y el trabajo
     * no llego a arrancar.
     */
    public function fail(DateTimeImmutable $failedAt, ReportExportFailure $reason): self
    {
        if (! $this->isInProgress()) {
            throw InvalidReportExportTransition::from($this->status, 'dar por fallido');
        }

        return $this->withLifecycle(
            status: ReportExportStatus::Failed,
            failedAt: $failedAt,
            failureReason: $reason,
        );
    }

    /**
     * `completed` → `purged`: el fichero se borro al vencer el plazo.
     *
     * **La fila se queda** (regla dura 5) con sus fechas, su huella y su recuento;
     * lo que desaparece es `filePath`, para que nadie intente servir un fichero
     * que ya no esta. El enlace vigente, si lo habia, muere con el.
     *
     * ## Y se MINIMIZA (RL-11)
     *
     * Al perder el fichero, la fila pierde tambien los tres campos que señalan a
     * personas: `scope` —la lista de departamentos que alguien alcanzaba— y los
     * dos filtros `employee_uuid` y `department_id` de `parameters`. Lo que queda
     * describe **que** se genero —periodo, granularidad, agrupacion, formato,
     * tamaño, huella, recuento— y ya no **de quien**.
     *
     * Es la razon por la que `report_exports` **no entra en `RetentionScope`**:
     * no necesita un plazo de retencion propio porque, pasado el suyo, deja de
     * contener datos personales. Conservar la fila indefinidamente es entonces
     * gratis y sigue contestando «¿salio de aqui un informe, cuando y a peticion
     * de quien?» — que es lo que regla dura 5 exige.
     *
     * **El hecho completo no se pierde**: `report_export.requested` guardo los
     * parametros y el alcance enteros en `audit_log`, que es solo-apendice y se
     * conserva cuatro años (RL-02).
     */
    public function purge(DateTimeImmutable $purgedAt): self
    {
        $this->assertStatusIs(ReportExportStatus::Completed, 'purgar');

        return $this->withPurgedFile($purgedAt);
    }

    /**
     * Emite un enlace nuevo e **invalida el anterior** (decision 3 de la ficha).
     *
     * Cada consulta del estado de una exportacion descargable rota el token: el
     * que estaba en la barra de direcciones de una pestaña abierta hace media
     * hora deja de valer en cuanto alguien vuelve a mirar la pantalla. Es lo que
     * hace que un enlace olvidado en un historial compartido tenga una vida util
     * de minutos y no de dias.
     *
     * @param  string  $tokenHash  SHA-256 en hexadecimal del token que se devuelve en la URL.
     *                             **El token no llega aqui**: ver el docblock de la clase.
     */
    public function issueDownloadToken(string $tokenHash, DateTimeImmutable $expiresAt): self
    {
        if (! $this->status->isDownloadable()) {
            throw InvalidReportExportTransition::from($this->status, 'emitir un enlace de descarga');
        }

        return $this->withDownloadLink($tokenHash, $expiresAt);
    }

    /**
     * Consume el enlace: **un solo uso**.
     *
     * Borra el token —repetir la misma URL responde `410 …link-used`—, sella la
     * fecha y suma una descarga. El recuento se conserva aunque el token
     * desaparezca: «este informe se descargo tres veces» es la pregunta que RS-05
     * obliga a poder contestar.
     */
    public function consumeDownloadToken(DateTimeImmutable $downloadedAt): self
    {
        return $this->withDownloadLink(
            tokenHash: null,
            tokenExpiresAt: null,
            downloadedAt: $downloadedAt,
            downloadCount: $this->downloadCount + 1,
        );
    }

    /**
     * ¿El token presentado es el vigente?
     *
     * Comparacion en **tiempo constante**: son dos huellas hexadecimales de la
     * misma longitud, y `===` sobre cadenas termina en el primer byte distinto.
     * Sobre un endpoint sin sesion y con limite por IP el riesgo practico es
     * remoto, pero comparar secretos con `===` es la clase de detalle que se
     * copia al siguiente sitio donde si importa.
     */
    public function downloadTokenMatches(string $presentedHash): bool
    {
        return $this->downloadTokenHash !== null
            && hash_equals($this->downloadTokenHash, $presentedHash);
    }

    /** No hay ningun enlace vivo: o nunca se emitio, o ya se uso. */
    public function downloadLinkWasConsumed(): bool
    {
        return $this->downloadTokenHash === null;
    }

    /** El enlace existe pero ya paso su ventana de `REPORTING_EXPORT_LINK_TTL_MINUTES`. */
    public function downloadLinkExpired(DateTimeImmutable $now): bool
    {
        return $this->downloadTokenExpiresAt !== null && $this->downloadTokenExpiresAt <= $now;
    }

    /**
     * Deja escrito por donde se aviso (decision 8).
     *
     * `panel` siempre que no haya salido un correo, y eso **no** es «no se
     * aviso»: la pantalla de informes es el canal de serie. Ver
     * {@see ReportExportNotificationChannel}.
     */
    public function markNotified(DateTimeImmutable $notifiedAt, ReportExportNotificationChannel $channel): self
    {
        return $this->withNotice($notifiedAt, $channel);
    }

    /**
     * @throws InvalidReportExportTransition
     */
    private function assertStatusIs(ReportExportStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw InvalidReportExportTransition::from($this->status, $action);
        }
    }

    /**
     * La copia con el estado del ciclo de vida cambiado.
     *
     * **Cinco parametros opcionales y ninguna forma de borrar**: los campos del
     * ciclo de vida solo se ponen, nunca se deshacen — una fecha de fallo no se
     * retira—.
     *
     * Esa es la razon de que haya cinco copiadores pequeños en lugar de uno con
     * veinte condiciones: cada uno **escribe lo que le toca y nada mas**, asi que
     * ninguno tiene que distinguir «no lo cambies» de «ponlo a nulo» — la
     * distincion que convierte un copiador en una cadena de ramas que nadie puede
     * leer, y que PHPStan cuenta como complejidad ciclomatica (doc 02 §3.5).
     */
    private function withLifecycle(
        ?ReportExportStatus $status = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $completedAt = null,
        ?DateTimeImmutable $failedAt = null,
        ?ReportExportFailure $failureReason = null,
    ): self {
        return $this->rebuild(
            status: $status ?? $this->status,
            startedAt: $startedAt ?? $this->startedAt,
            completedAt: $completedAt ?? $this->completedAt,
            failedAt: $failedAt ?? $this->failedAt,
            failureReason: $failureReason ?? $this->failureReason,
            filePath: $this->filePath,
            fileName: $this->fileName,
            sizeBytes: $this->sizeBytes,
            sha256: $this->sha256,
            rowCount: $this->rowCount,
            criteria: $this->criteria,
            expiresAt: $this->expiresAt,
            purgedAt: $this->purgedAt,
            downloadTokenHash: $this->downloadTokenHash,
            downloadTokenExpiresAt: $this->downloadTokenExpiresAt,
            downloadedAt: $this->downloadedAt,
            downloadCount: $this->downloadCount,
            notifiedAt: $this->notifiedAt,
            notificationChannel: $this->notificationChannel,
        );
    }

    /**
     * La copia con el fichero recien escrito, **y con el estado a `completed`**.
     *
     * Todo obligatorio: el `CHECK` `report_exports_chk_completed_is_complete`
     * exige los seis a la vez, y una firma que admitiera nulos permitiria
     * construir en memoria el estado que la base de datos rechaza.
     *
     * @param  list<string>  $criteria
     */
    private function withFile(
        DateTimeImmutable $completedAt,
        string $filePath,
        string $fileName,
        int $sizeBytes,
        string $sha256,
        int $rowCount,
        array $criteria,
        DateTimeImmutable $expiresAt,
    ): self {
        return $this->rebuild(
            status: ReportExportStatus::Completed,
            startedAt: $this->startedAt,
            completedAt: $completedAt,
            failedAt: $this->failedAt,
            failureReason: $this->failureReason,
            filePath: $filePath,
            fileName: $fileName,
            sizeBytes: $sizeBytes,
            sha256: $sha256,
            rowCount: $rowCount,
            criteria: $criteria,
            expiresAt: $expiresAt,
            purgedAt: $this->purgedAt,
            downloadTokenHash: $this->downloadTokenHash,
            downloadTokenExpiresAt: $this->downloadTokenExpiresAt,
            downloadedAt: $this->downloadedAt,
            downloadCount: $this->downloadCount,
            notifiedAt: $this->notifiedAt,
            notificationChannel: $this->notificationChannel,
        );
    }

    /**
     * La copia sin fichero: la purga por caducidad.
     *
     * `filePath` a nulo **siempre**, no «si procede»: es lo que impide que alguien
     * intente servir un fichero que ya no existe. El enlace vigente muere con el.
     * El nombre, la huella y el recuento se conservan (regla dura 5).
     */
    private function withPurgedFile(DateTimeImmutable $purgedAt): self
    {
        /*
         * **El unico copiador que no pasa por `rebuild()`**, y por una razon
         * concreta: es la unica transicion que toca `parameters` y `scope`, que
         * `rebuild()` declara inmutables **a proposito** —para que ninguna otra
         * pueda cambiarlos por descuido—. Abrirlos alli para que los use uno solo
         * seria quitar esa garantia a los otros cinco.
         */
        return new self(
            id: $this->id,
            uuid: $this->uuid,
            kind: $this->kind,
            format: $this->format,
            status: ReportExportStatus::Purged,
            // RL-11: sin los dos filtros que señalan a una persona o a un equipo.
            parameters: $this->parameters->minimised(),
            // Y sin la lista de departamentos que alcanzaba quien lo pidio.
            scope: null,
            requestedByUserId: $this->requestedByUserId,
            requestedByUuid: $this->requestedByUuid,
            requestedByName: $this->requestedByName,
            requestedAt: $this->requestedAt,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failedAt: $this->failedAt,
            failureReason: $this->failureReason,
            filePath: null,
            fileName: $this->fileName,
            sizeBytes: $this->sizeBytes,
            sha256: $this->sha256,
            rowCount: $this->rowCount,
            criteria: $this->criteria,
            expiresAt: $this->expiresAt,
            purgedAt: $purgedAt,
            downloadTokenHash: null,
            downloadTokenExpiresAt: null,
            downloadedAt: $this->downloadedAt,
            downloadCount: $this->downloadCount,
            notifiedAt: $this->notifiedAt,
            notificationChannel: $this->notificationChannel,
        );
    }

    /**
     * La copia con el enlace de descarga puesto **o retirado**, y con la descarga
     * contada cuando se retira por consumo.
     *
     * Los dos campos del enlace se escriben siempre juntos, que es lo que declara
     * el `CHECK` `report_exports_chk_download_link_is_whole`: una huella sin fecha
     * seria un enlace que no caduca nunca, y una fecha sin huella, un enlace que
     * no abre nada.
     */
    private function withDownloadLink(
        ?string $tokenHash,
        ?DateTimeImmutable $tokenExpiresAt,
        ?DateTimeImmutable $downloadedAt = null,
        ?int $downloadCount = null,
    ): self {
        return $this->rebuild(
            status: $this->status,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failedAt: $this->failedAt,
            failureReason: $this->failureReason,
            filePath: $this->filePath,
            fileName: $this->fileName,
            sizeBytes: $this->sizeBytes,
            sha256: $this->sha256,
            rowCount: $this->rowCount,
            criteria: $this->criteria,
            expiresAt: $this->expiresAt,
            purgedAt: $this->purgedAt,
            downloadTokenHash: $tokenHash,
            downloadTokenExpiresAt: $tokenExpiresAt,
            downloadedAt: $downloadedAt ?? $this->downloadedAt,
            downloadCount: $downloadCount ?? $this->downloadCount,
            notifiedAt: $this->notifiedAt,
            notificationChannel: $this->notificationChannel,
        );
    }

    /** La copia con el aviso sellado. */
    private function withNotice(DateTimeImmutable $notifiedAt, ReportExportNotificationChannel $channel): self
    {
        return $this->rebuild(
            status: $this->status,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failedAt: $this->failedAt,
            failureReason: $this->failureReason,
            filePath: $this->filePath,
            fileName: $this->fileName,
            sizeBytes: $this->sizeBytes,
            sha256: $this->sha256,
            rowCount: $this->rowCount,
            criteria: $this->criteria,
            expiresAt: $this->expiresAt,
            purgedAt: $this->purgedAt,
            downloadTokenHash: $this->downloadTokenHash,
            downloadTokenExpiresAt: $this->downloadTokenExpiresAt,
            downloadedAt: $this->downloadedAt,
            downloadCount: $this->downloadCount,
            notifiedAt: $notifiedAt,
            notificationChannel: $channel,
        );
    }

    /**
     * El unico sitio donde se construye una copia, y **sin una sola rama**.
     *
     * Los diecinueve campos mutables son obligatorios: quien copia dice que pasa
     * con todos, uno a uno. Es verboso a proposito —la alternativa era un metodo
     * con veinte `??` y tres banderas de «borrar», que PHPStan rechaza por
     * complejidad y que nadie puede leer— y tiene una consecuencia util: **una
     * transicion no puede olvidarse de copiar un campo**, porque la firma exige
     * nombrarlo.
     *
     * Los inmutables —`id`, `uuid`, `kind`, `format`, `parameters`, `scope` y el
     * solicitante— no aparecen: no hay ninguna transicion que los cambie, y que
     * no se puedan pasar es lo que lo garantiza.
     *
     * @param  list<string>  $criteria
     */
    private function rebuild(
        ReportExportStatus $status,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?DateTimeImmutable $failedAt,
        ?ReportExportFailure $failureReason,
        ?string $filePath,
        ?string $fileName,
        ?int $sizeBytes,
        ?string $sha256,
        ?int $rowCount,
        array $criteria,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $purgedAt,
        ?string $downloadTokenHash,
        ?DateTimeImmutable $downloadTokenExpiresAt,
        ?DateTimeImmutable $downloadedAt,
        int $downloadCount,
        ?DateTimeImmutable $notifiedAt,
        ?ReportExportNotificationChannel $notificationChannel,
    ): self {
        return new self(
            id: $this->id,
            uuid: $this->uuid,
            kind: $this->kind,
            format: $this->format,
            status: $status,
            parameters: $this->parameters,
            scope: $this->scope,
            requestedByUserId: $this->requestedByUserId,
            requestedByUuid: $this->requestedByUuid,
            requestedByName: $this->requestedByName,
            requestedAt: $this->requestedAt,
            startedAt: $startedAt,
            completedAt: $completedAt,
            failedAt: $failedAt,
            failureReason: $failureReason,
            filePath: $filePath,
            fileName: $fileName,
            sizeBytes: $sizeBytes,
            sha256: $sha256,
            rowCount: $rowCount,
            criteria: $criteria,
            expiresAt: $expiresAt,
            purgedAt: $purgedAt,
            downloadTokenHash: $downloadTokenHash,
            downloadTokenExpiresAt: $downloadTokenExpiresAt,
            downloadedAt: $downloadedAt,
            downloadCount: $downloadCount,
            notifiedAt: $notifiedAt,
            notificationChannel: $notificationChannel,
        );
    }
}
