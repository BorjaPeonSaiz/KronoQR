<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Infrastructure\Export\AdoptionReportDigest;
use Symfony\Component\HttpFoundation\Response;

/**
 * El cuadro de impacto ya compuesto **en memoria**, con lo que hace falta para
 * auditarlo antes de entregarlo (**RF-IN-08**).
 *
 * ## Por que aqui no hay streaming, al contrario que el informe por periodo
 *
 * Aquel puede tener quince mil filas y por eso se transmite. Este tiene **doce
 * indicadores y cuatro origenes**: unos kilobytes en el peor caso. Y componerlo
 * entero antes de responder no es una concesion, es un requisito:
 *
 *   1. **El asiento de `audit_log` se escribe ANTES de que salga un byte** (regla
 *      dura 6, ADR-027). Con una respuesta transmitida, un fallo del asiento
 *      llegaria cuando el fichero ya esta en el navegador de quien lo pidio.
 *   2. **El asiento dice el tamaño del fichero**, y el tamaño de algo que todavia
 *      se esta escribiendo no se sabe.
 *
 * El informe por periodo puede permitirse transmitir porque su asiento lo escribe
 * la consulta, antes de que empiece la respuesta; aqui el asiento depende del
 * fichero, asi que el orden se invierte.
 *
 * ## `digest` es del contenido y `sizeBytes` del fichero
 *
 * No son lo mismo y los dos hacen falta. La huella es la misma para el CSV, el
 * XLSX y el PDF del mismo cuadro —es lo que permite confirmar que dos papeles
 * dicen lo mismo— y el tamaño es del artefacto concreto que salio, que es lo que
 * permite reconocer un adjunto en una conversacion. Ver
 * {@see AdoptionReportDigest}.
 */
final readonly class AdoptionReportFile
{
    /**
     * @param  string  $digest  Huella SHA-256 del **contenido** del cuadro.
     * @param  int  $rowCount  Indicadores del fichero, sin contar el reparto por origen.
     * @param  list<string>  $criteria  Los criterios ya traducidos, para la cabecera.
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $bytes,
        public string $digest,
        public int $rowCount,
        public array $criteria,
    ) {}

    public function sizeBytes(): int
    {
        return \strlen($this->bytes);
    }

    /**
     * La respuesta, con las cabeceras que los tres formatos comparten.
     *
     * ## `no-store, private` aunque no haya datos personales
     *
     * Aqui no protege a nadie de una fuga: el cuadro es un agregado. Protege de algo
     * mas prosaico —una copia cacheada por un proxy del hotel convertiria «el cuadro
     * de marzo» en la respuesta de quien pida abril desde la misma red— y de la
     * inconsistencia: es la cabecera de todas las descargas del producto, y no hay
     * motivo para que esta sea la excepcion que alguien tenga que justificar.
     *
     * ## Las dos cabeceras de comprobacion, y la tercera
     *
     * `X-Kronoqr-Report-Digest` y `X-Kronoqr-Report-Rows` son las mismas que el
     * informe por periodo: con ellas quien descarga puede decir que se llevo y
     * comprobar que llego entero sin abrir el fichero.
     *
     * `X-Kronoqr-Export-Criteria` lleva los criterios **ya traducidos al idioma de
     * la instalacion**, unidos por `\n` y en base64 de UTF-8 porque una cabecera HTTP
     * no admite ni acentos ni saltos de linea. Van **ademas** dentro del fichero, al
     * contrario que en la salida a nomina: es lo que el panel lee para enseñarlos
     * junto al boton de descarga sin volver a pedir el cuadro en JSON.
     */
    public function toResponse(): Response
    {
        return new Response($this->bytes, 200, [
            'Content-Type' => $this->contentType,
            // Sin comillas y sin `filename*`: el nombre solo lleva el concepto, el
            // periodo y la extension, asi que no hay ningun caracter que escapar y
            // ningun dato personal que ocultar (regla dura 21).
            'Content-Disposition' => 'attachment; filename='.$this->filename,
            'Cache-Control' => 'no-store, private',
            'Content-Length' => (string) $this->sizeBytes(),
            'X-Kronoqr-Report-Digest' => $this->digest,
            'X-Kronoqr-Report-Rows' => (string) $this->rowCount,
            'X-Kronoqr-Export-Criteria' => base64_encode(implode("\n", $this->criteria)),
        ]);
    }
}
