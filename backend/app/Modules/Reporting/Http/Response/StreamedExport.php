<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Un fichero de informe listo para transmitir, con las cabeceras que los tres
 * formatos comparten (**RF-IN-04**).
 *
 * ## Por que existe en vez de que cada escritor devuelva su `StreamedResponse`
 *
 * Porque las cuatro cabeceras son las mismas para el CSV, el XLSX y el PDF, y el
 * contrato las declara una sola vez. Con tres escritores construyendo su propia
 * respuesta, `Cache-Control: no-store` acabaria puesto en dos de los tres — y el
 * que se olvide es el que deja una copia del registro horario nominal en el
 * cache de un proxy o en el disco de un navegador compartido.
 *
 * ## `no-store, private` no es opcional
 *
 * El cuerpo lleva horas de personas identificadas. Mismo criterio que la
 * exportacion legal y que el historico propio del portal.
 *
 * ## Las dos cabeceras de comprobacion
 *
 * `X-Kronoqr-Report-Digest` es la huella del contenido —la misma que imprime el
 * pie del PDF y la misma para los tres formatos del mismo informe— y
 * `X-Kronoqr-Report-Rows` cuantas filas lleva. Con las dos, quien descarga puede
 * decir que se llevo y comprobar que llego entero sin abrir el fichero. Es el
 * mismo servicio que prestan las dos cabeceras de recuento de la exportacion
 * legal.
 */
final readonly class StreamedExport
{
    /**
     * @param  Closure(): void  $body  Escribe el fichero en `php://output`.
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $digest,
        public int $rowCount,
        private Closure $body,
    ) {}

    public function toResponse(): StreamedResponse
    {
        return new StreamedResponse($this->body, 200, [
            'Content-Type' => $this->contentType,
            // Sin comillas y sin `filename*`: el nombre solo lleva el periodo y
            // la extension, asi que no hay ningun caracter que escapar y ningun
            // dato personal que ocultar (regla dura 21).
            'Content-Disposition' => 'attachment; filename='.$this->filename,
            'Cache-Control' => 'no-store, private',
            'X-Kronoqr-Report-Digest' => $this->digest,
            'X-Kronoqr-Report-Rows' => (string) $this->rowCount,
        ]);
    }
}
