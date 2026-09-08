<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use Closure;

/**
 * De donde salen las filas de cada fichero del ZIP (**RF-PD-14**, RL-20).
 *
 * ## `iterable` y no `array`, y esa firma es media tarea
 *
 * Devolver un array obligaria a tener en memoria la tabla entera antes de
 * escribir la primera linea, y la tabla entera son cuatro años de `scan_events`
 * de una plantilla de 500 personas. La implementacion recorre con un **cursor de
 * servidor** (`DECLARE ... FETCH FORWARD`, la tecnica de
 * `DatabaseLegalExportSource`) porque el driver de PostgreSQL trae al cliente el
 * resultado **entero** de un `SELECT` normal: un generador sobre `cursor()`
 * seria streaming de mentira, con las decenas de miles de filas ya en memoria
 * antes de ceder la primera.
 *
 * La prueba de integracion con volumen mide `memory_get_peak_usage(true)` y
 * exige que quede por debajo de 128 MiB. No es «que no reviente»: una
 * implementacion que cargara una tabla en memoria lo superaria ya con la semilla
 * de 90 dias, y con el cursor cuatro años cuestan lo mismo que noventa dias.
 *
 * ## La transaccion la abre quien recorre
 *
 * Un cursor sin `HOLD` **exige transaccion**, y ademas es lo que hace que lo
 * exportado sea el registro en un instante y no un promedio de los minutos que
 * tarde la escritura. Por eso {@see self::within()}: quien orquesta abre la
 * transaccion una vez y recorre todos los conjuntos dentro de ella, en lugar de
 * abrir una por fichero y entregar una foto distinta en cada uno.
 */
interface DataExportSource
{
    /**
     * Ejecuta el trabajo dentro de una unica transaccion.
     *
     * `Closure` y no `callable` porque el adaptador la pasa tal cual al gestor
     * de transacciones del framework, que exige una: con `callable` habria que
     * envolverla en otra sin ganar nada.
     *
     * Devuelve `mixed` y quien llama declara la forma: un tipo generico aqui
     * obligaria a resolver plantillas a traves de una frontera de puerto, y lo
     * que se gana —una anotacion— no vale el ruido en la unica linea que de
     * verdad importa de este puerto, que es que **haya una sola transaccion**.
     *
     * @param  Closure(): mixed  $work
     */
    public function within(Closure $work): mixed;

    /**
     * Las filas de ese conjunto, en el orden estable de su clave interna.
     *
     * Las claves de cada fila son las columnas de la consulta; quien escribe
     * aplica despues la lista de permitidos del conjunto (regla dura 21 aplicada
     * a lo que sale del producto).
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(ExportedDataset $dataset): iterable;

    /**
     * La zona horaria del centro, para el manifiesto y el `README.md`.
     *
     * Vive aqui y no en un puerto propio porque es el mismo `SELECT` sobre la
     * misma conexion y dentro de la misma transaccion: la zona con la que se
     * interpretan los instantes del fichero tiene que ser la que habia cuando se
     * leyeron, no la de un segundo despues.
     */
    public function siteTimezone(): string;
}
