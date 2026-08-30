<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use DomainException;

/**
 * El informe pedido no se entrega en el acto (**RNF-P-05**, `/informe-nuevo`
 * paso 5).
 *
 * ## La regla, escrita donde se aplica
 *
 * El paso 5 de la skill fija el criterio: por debajo de 5 s medidos con volumen
 * real, respuesta directa; por encima de 10 s o con mas de tres meses de datos,
 * cola con enlace caducable —que es RF-IN-06, **tarea 3.9**—; entre 5 y 10 s, se
 * optimiza antes de aceptarlo como sincrono.
 *
 * Esta tarea entrega el camino sincrono. Mientras el asincrono no exista, la
 * respuesta honesta a una peticion que se sale del presupuesto **no es
 * intentarlo**: un informe que tarda cuarenta segundos ocupa un proceso de PHP,
 * una conexion de PostgreSQL y acaba en un `504` del proxy, con la misma base de
 * datos que atiende el fichaje ocupada mientras tanto (RNF-P-02, regla dura 19).
 * Es preferible un `422` que dice que hay que trocear el rango.
 *
 * ## Tres formas de llegar aqui, y las tres significan lo mismo
 *
 * - **El rango supera el techo** (tres meses por omision): se sabe antes de
 *   tocar la base de datos y es la barata.
 * - **El informe produciria demasiadas filas**: sujetos × cubos por encima del
 *   presupuesto. Un informe diario de la plantilla entera de un trimestre son
 *   decenas de miles de filas que nadie va a leer en una pantalla.
 * - **La consulta agoto su `statement_timeout`**: la unica que se descubre
 *   ejecutando, y por eso el limite se pone en PostgreSQL y no con un
 *   cronometro en PHP. Un `SET LOCAL statement_timeout` corta la consulta en el
 *   servidor y libera la conexion; medir en PHP el tiempo de algo que ya ha
 *   terminado solo sirve para escribirlo en el log.
 *
 * ## Es un `422` y lleva la salida escrita
 *
 * No un `500` —no se ha roto nada— ni un `503` —el servicio esta bien—. Quien lo
 * recibe tiene algo que cambiar en la peticion, y el `detail` se lo dice: reduce
 * el rango, sube la granularidad o espera a la generacion en diferido de
 * RF-IN-06.
 */
final class ReportTooLargeForSynchronousDelivery extends DomainException
{
    public static function rangeTooWide(int $days, int $maximumDays): self
    {
        return new self(
            'El informe abarca '.$days.' dias y el maximo que se entrega en el acto es '.$maximumDays
            .'. Reduce el rango o pide la generacion en diferido.',
        );
    }

    public static function tooManyRows(int $estimatedRows, int $maximumRows): self
    {
        return new self(
            'El informe produciria unas '.$estimatedRows.' filas y el maximo que se entrega en el acto es '
            .$maximumRows.'. Sube la granularidad, acota el departamento o pide la generacion en diferido.',
        );
    }

    public static function timedOut(int $timeoutSeconds): self
    {
        return new self(
            'El informe ha superado los '.$timeoutSeconds.' segundos y se ha cancelado. '
            .'Reduce el rango, sube la granularidad o pide la generacion en diferido.',
        );
    }
}
