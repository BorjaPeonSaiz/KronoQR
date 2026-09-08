<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use Throwable;

/**
 * No se pudo escribir la exportacion integra en el disco (**RF-PD-14**).
 *
 * ## Por que es una excepcion propia y no una `RuntimeException`
 *
 * Porque {@see DataExportFailure} tiene que poder distinguir «no cabe en el
 * disco» de «la base de datos fallo» sin mirar el nombre de una clase del
 * framework ni el texto del mensaje. Con una `RuntimeException` generica, el
 * clasificador tendria que adivinar —y adivinaria mal el dia que otra pieza
 * lanzara la misma clase—, y el cliente veria `unexpected` cuando lo unico que
 * pasa es que le falta espacio.
 *
 * **El mensaje sigue siendo util y sigue sin salir del servidor.** Lleva la ruta
 * y que hacer, porque quien esta delante de la terminal lo necesita y el
 * fabricante no tiene acceso a esta maquina (ADR-016); lo que llega al panel y a
 * la fila es solo el codigo `write_failed` (regla dura 21).
 */
final class DataExportWriteFailed extends ProductDomainException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
