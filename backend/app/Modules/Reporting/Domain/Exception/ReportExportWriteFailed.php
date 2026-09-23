<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use RuntimeException;
use Throwable;

/**
 * No se pudo escribir el fichero del informe en diferido (**RF-IN-06**).
 *
 * ## Existe para que el motivo del fallo no sea una adivinanza
 *
 * Es, con diferencia, el fallo mas probable de esta tarea en produccion: el
 * disco lleno, el directorio de `REPORTING_EXPORT_PATH` sin permisos, un volumen
 * que no esta montado. Sin un tipo propio, el caso de uso tendria que clasificar
 * por el mensaje de la excepcion —que es exactamente lo que la regla dura 21
 * prohibe guardar— o meterlo todo en `unexpected`, y entonces el panel diria
 * «abre una incidencia» cuando lo que hay que hacer es mirar `df -h`.
 *
 * ## El mensaje no sale de aqui
 *
 * Lo que llega a la fila es el codigo `write_failed`
 * ({@see ReportExportFailure}). El
 * mensaje va al log tecnico junto al `uuid`, y **no lleva la ruta absoluta**: en
 * el log del servidor esa ruta no aporta nada que el nombre del fichero no diga,
 * y el log viaja al fabricante dentro del paquete de diagnostico.
 */
final class ReportExportWriteFailed extends RuntimeException
{
    public static function of(string $what, ?Throwable $cause = null): self
    {
        return new self('No se pudo escribir el informe en diferido: '.$what.'.', previous: $cause);
    }
}
