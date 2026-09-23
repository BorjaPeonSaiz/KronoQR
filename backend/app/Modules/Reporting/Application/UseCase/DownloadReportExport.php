<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Domain\Event\ReportExportDownloaded;
use App\Modules\Reporting\Domain\Exception\ReportExportLinkUnavailable;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Consume el enlace de un solo uso y autoriza la entrega del fichero
 * (**RF-IN-06**, ADR-041, RS-05, decision 3 de la ficha 3.9).
 *
 * ## Esta es la unica autorizacion que tiene la descarga
 *
 * No hay sesion: la ruta va fuera del grupo autenticado porque un enlace que se
 * abre con un clic no lleva `Authorization`. Lo que la protege son tres cosas a
 * la vez, y las tres tienen que valer:
 *
 * 1. El `uuid` v7 de la exportacion, que solo conoce quien la pidio.
 * 2. Un token de 32 bytes que **se consume**: repetir la URL responde `410`.
 * 3. Una ventana de `REPORTING_EXPORT_LINK_TTL_MINUTES`.
 *
 * ## El bloqueo de fila es lo que hace que «un solo uso» sea cierto
 *
 * `lockByUuid()` toma `FOR UPDATE` dentro de la transaccion. Sin el, dos
 * peticiones simultaneas con el mismo token —un gestor de descargas que abre dos
 * conexiones, un doble clic— leerian las dos el token vigente, las dos
 * entregarian el fichero y el contador diria «una descarga». Con el, la segunda
 * espera, lee la fila con el token ya borrado y recibe `410`.
 *
 * ## Tres desenlaces y cada uno dice algo distinto
 *
 * - **`null`** — no existe, fallo, se purgo, sigue en curso, el fichero ya no
 *   esta en el disco, **o el token no es el vigente y hay otro vivo**. El borde
 *   responde `404` sin detalle: enumerar por que no esta no le cambia la accion a
 *   quien lo recibe, y confirmaria la existencia de una exportacion ajena.
 * - **`410 …link-used`** — el enlace existio y ya se gasto. El fichero sigue ahi:
 *   la salida es volver a la pantalla y pedir otro.
 * - **`410 …link-expired`** — el enlace existio y se le paso el plazo. Misma
 *   salida.
 *
 * ## El asiento va ANTES de entregar el fichero
 *
 * Si se escribiera despues, una descarga que se corta a la mitad sacaria del
 * servidor un fichero con las horas de la plantilla sin dejar rastro. Al reves
 * puede quedar constancia de una descarga que no se completo, y eso es
 * preferible con mucho: sobra informacion en el trail en lugar de faltar justo
 * en la pregunta que hay que contestar ante una brecha (RL-15).
 *
 * El actor del asiento es **quien pidio el informe**, que es quien recibio el
 * enlace: aqui no hay sesion de la que sacarlo. Ver {@see ReportExportDownloaded}.
 */
final readonly class DownloadReportExport
{
    public function __construct(
        private ReportExportRepository $exports,
        private ReportExportStorage $storage,
        private ReportingEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  string  $token  El token en claro tal y como llego en la URL.
     *
     * @throws ReportExportLinkUnavailable si el enlace se uso o caduco
     */
    public function handle(string $uuid, string $token): ?ReportExport
    {
        /** @var ReportExport|null $export */
        $export = $this->connection->transaction(function () use ($uuid, $token): ?ReportExport {
            $export = $this->exports->lockByUuid($uuid);
            $now = $this->clock->now();

            if ($export === null || ! $this->canBeHandedOver($export, $token, $now)) {
                return null;
            }

            $consumed = $export->consumeDownloadToken($now);

            $this->exports->save($consumed);

            $this->events->publish(new ReportExportDownloaded(
                uuid: $consumed->uuid,
                kind: $consumed->kind->value,
                format: $consumed->format,
                fileName: $consumed->fileName ?? '',
                sha256: $consumed->sha256 ?? '',
                sizeBytes: $consumed->sizeBytes ?? 0,
                downloadCount: $consumed->downloadCount,
                requestedByUserId: $consumed->requestedByUserId,
                occurredAt: $now,
            ));

            return $consumed;
        });

        return $export;
    }

    /**
     * Las cuatro comprobaciones que separan «toma tu fichero» de los tres noes.
     *
     * Extraidas de la transaccion porque juntas pasaban del techo de complejidad
     * del §3.5, y porque leidas seguidas se entiende **el orden**, que no es
     * arbitrario:
     *
     * 1. **Hay fichero y no ha caducado.** La caducidad se comprueba aqui ademas
     *    de al emitir el enlace: la purga corre una vez al dia, y entre el
     *    vencimiento y esa pasada la fila sigue `completed` con su fichero en el
     *    disco. La caducidad es una promesa al cliente, no un efecto secundario de
     *    cuando pase el planificador.
     * 2. **El token es el vigente.** Si no lo es, `410 …link-used` **solo cuando
     *    consta una descarga**: sin token hay dos situaciones que desde fuera se
     *    parecen —el enlace se gasto, o nunca se emitio ninguno— y responder `410`
     *    a las dos convertiria esta ruta sin sesion en una forma de preguntar «¿esa
     *    exportacion existe y esta lista?» componiendo una URL a mano.
     *    `downloaded_at` es lo que las separa, y es un hecho de la fila.
     * 3. **El enlace no ha caducado**, que es el otro `410`.
     * 4. **El fichero sigue en el disco.** Se comprueba DESPUES del token y ANTES
     *    de consumirlo: si alguien vacio el directorio a mano, lo correcto es
     *    `404` y no gastar el enlace contra nada.
     *
     * @throws ReportExportLinkUnavailable si el enlace se uso o caduco
     */
    private function canBeHandedOver(ReportExport $export, string $token, DateTimeImmutable $now): bool
    {
        if (! $export->isDownloadable() || $export->expired($now)) {
            return false;
        }

        if (! $export->downloadTokenMatches(hash('sha256', $token))) {
            if ($export->downloadLinkWasConsumed() && $export->downloadedAt !== null) {
                throw ReportExportLinkUnavailable::used();
            }

            return false;
        }

        if ($export->downloadLinkExpired($now)) {
            throw ReportExportLinkUnavailable::expired();
        }

        return $this->storage->exists((string) $export->filePath);
    }
}
