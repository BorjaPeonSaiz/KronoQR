<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Un fichero ya escrito del ZIP: cuantas filas de datos lleva y cual es su
 * huella (**RF-PD-14**, RL-20).
 *
 * ## Por que la huella va por fichero y no solo del ZIP entero
 *
 * La del ZIP dice si el paquete llego intacto. La de cada fichero dice **cual**
 * se estropeo si no llego, y permite comprobar un fichero suelto despues de
 * haberlo extraido —que es como se trabaja con una exportacion de varios
 * gigabytes: se descomprime una vez y se manejan los ficheros por separado—.
 *
 * ## `rows` cuenta filas de DATOS, nunca la cabecera
 *
 * Es lo que se compara con un `SELECT count(*)` de la base de datos, y esa
 * comparacion es exactamente la que hace la prueba de volumen. Si contara la
 * cabecera del CSV, cada conjunto saldria con una fila de mas y la comprobacion
 * mas util del manifiesto no serviria.
 *
 * ## `dataset` y `name` son dos cosas, y se separan a proposito
 *
 * `name` es el nombre **del fichero** (`employees.csv`): es la clave de
 * `manifest.json > files`, para que comparar con `sha256sum employees.csv` sea
 * inmediato. `dataset` es el nombre **del conjunto** (`employees`): es la clave
 * de `row_counts`, en la fila de la base de datos y en el contrato, para que no
 * dependa de si el fichero salio en CSV o en JSON.
 *
 * `dataset` es `null` en `README.md` y `manifest.json`: no son datos, no cuentan
 * en `row_counts` y no falsean el total que el cliente compara contra su base de
 * datos.
 */
final readonly class DataExportFile
{
    public function __construct(
        public string $name,
        public int $rows,
        public string $sha256,
        public int $sizeBytes,
        public ?string $dataset = null,
    ) {}
}
