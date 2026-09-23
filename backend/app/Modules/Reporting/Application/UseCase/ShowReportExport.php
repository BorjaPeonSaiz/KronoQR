<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Support\IssuedReportExportLink;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * El estado de un informe en diferido y, si esta listo, **un enlace de descarga
 * nuevo** (**RF-IN-06**, ADR-041, decision 3 de la ficha 3.9).
 *
 * ## Consultar el estado EMITE el enlace, y eso es lo que lo hace seguro
 *
 * Cada consulta de una exportacion descargable acuña un token de 32 bytes
 * aleatorios, guarda **solo su `sha256`** e invalida el anterior. Asi:
 *
 * 1. **El enlace no existe hasta que alguien mira la pantalla.** No hay ninguna
 *    URL que se pueda predecir desde el `202` ni desde la lista.
 * 2. **Vive minutos, no dias.** `REPORTING_EXPORT_LINK_TTL_MINUTES`, 15 de
 *    serie.
 * 3. **El anterior muere.** Un enlace olvidado en el historial de un navegador
 *    compartido —o en el portapapeles— deja de valer en cuanto alguien vuelve a
 *    abrir la pantalla de informes.
 * 4. **La fila no guarda el secreto.** Quien lea la base de datos ve una huella,
 *    no una llave. Es el mismo criterio que `credentials.signed_key` y que
 *    `pin_hash`.
 *
 * Y por eso **esto es una escritura disfrazada de `GET`**: el contrato lo dice
 * por escrito. Es la misma lectura que hace `GET /credentials/{uuid}/print` al
 * sellar la impresion — consultar tiene un efecto, y esconderlo seria peor que
 * declararlo.
 *
 * ## Por que el token viaja en la URL y no en una cabecera
 *
 * Porque un enlace que se abre con un clic no lleva `Authorization` (ADR-041).
 * Eso es exactamente lo que obliga a que sea **de un solo uso, corto y ligado a
 * una fila**: es la misma tecnica que las URL firmadas de Laravel, hecha a mano
 * para que el secreto no sea `APP_KEY` —que firma todo lo demas— sino un token
 * por descarga que se consume.
 *
 * ## Sin enlace cuando no lo hay, y no es un error
 *
 * Una exportacion `pending`, `running`, `failed`, `purged` o cuyo fichero ya
 * caduco devuelve `download: null`. La pantalla enseña el estado y sigue
 * sondeando; no hay nada que decir mas alla de lo que la propia fila ya dice.
 *
 * ## El dueño se comprueba en la consulta
 *
 * `findByUuidFor()` acota por `requested_by_user_id`, asi que la exportacion de
 * otra persona **no existe** para quien pregunta: `404` y no `403`, para no
 * confirmar que existe (decision 2).
 */
final readonly class ShowReportExport
{
    /**
     * Bytes de aleatoriedad del token. Treinta y dos, que es lo mismo que usa el
     * producto para el secreto de un token de dispositivo: por debajo de eso, un
     * enlace sin sesion detras empieza a ser adivinable con paciencia y ancho de
     * banda, y lo unico que lo frena es la zona de limitacion.
     */
    private const int TOKEN_BYTES = 32;

    public function __construct(
        private ReportExportRepository $exports,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * Minutos que vive el enlace. Ya resuelto por quien construye (regla dura
         * 14): el caso de uso no consulta la configuracion, y asi una prueba
         * puede fijar cero minutos sin tocar el estado global del proceso.
         */
        private int $linkTtlMinutes,
    ) {}

    public function handle(string $uuid, int $requestedByUserId): ?IssuedReportExportLink
    {
        /*
         * **TODO DENTRO DE UNA TRANSACCION, CON LA FILA BLOQUEADA.**
         *
         * Esto es un `GET` que escribe, asi que tiene las mismas carreras que
         * cualquier escritura: entre leer la fila y guardarla con el token nuevo
         * pasan unos milisegundos, y en ese hueco caben una descarga —que suma
         * `download_count` y sella `downloaded_at`— y la purga de madrugada —que
         * borra el fichero y limpia `file_path`—. Sin bloqueo, la escritura de
         * diecinueve columnas de aqui deshacia la de ellas: la descarga dejaba de
         * estar contada, o peor, la fila resucitaba el `file_path` de un fichero
         * que ya no existe y el panel ofrecia una descarga que responde `404`.
         *
         * El dueño entra en la misma consulta que el bloqueo (decision 2): la
         * exportacion de otra persona **no existe** para quien pregunta, y eso es
         * lo que produce el `404` del contrato en lugar de un `403` que
         * confirmaria que existe.
         */
        /** @var IssuedReportExportLink|null $issued */
        $issued = $this->connection->transaction(
            function () use ($uuid, $requestedByUserId): ?IssuedReportExportLink {
                $export = $this->exports->lockByUuidFor($uuid, $requestedByUserId);

                if ($export === null) {
                    return null;
                }

                $now = $this->clock->now();

                if (! $export->canIssueDownloadLink($now)) {
                    return new IssuedReportExportLink($export, null, null);
                }

                /*
                 * El token se genera AQUI y solo existe en memoria y en la
                 * respuesta: lo que se guarda es su huella. Ni el modelo de
                 * dominio ni el repositorio llegan a verlo, que es lo que impide
                 * que acabe en un log por descuido.
                 */
                $token = bin2hex(random_bytes(self::TOKEN_BYTES));
                $expiresAt = $now->modify('+'.max(1, $this->linkTtlMinutes).' minutes');

                $issued = $export->issueDownloadToken(hash('sha256', $token), $expiresAt);

                $this->exports->save($issued);

                return new IssuedReportExportLink($issued, $token, $expiresAt);
            }
        );

        return $issued;
    }
}
