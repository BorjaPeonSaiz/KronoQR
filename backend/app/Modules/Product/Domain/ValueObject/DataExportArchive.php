<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El ZIP terminado: donde esta, cuanto ocupa y cual es su huella (**RF-PD-14**).
 *
 * La huella se calcula **sobre el fichero ya cerrado**, no sobre lo que se
 * pensaba escribir: es la que se guarda en `data_exports.sha256`, la que va al
 * asiento de `audit_log` y la que viaja en `X-Kronoqr-Export-Sha256`. Que las
 * tres sean el mismo valor es lo que permite a un cliente comprobar con
 * `sha256sum` que el fichero que tiene en su portatil es el que salio del
 * servidor, sin abrirlo y sin KronoQR delante.
 */
final readonly class DataExportArchive
{
    public function __construct(
        public string $path,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
    ) {}
}
