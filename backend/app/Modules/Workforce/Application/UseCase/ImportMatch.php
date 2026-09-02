<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\ImportMessage;

/**
 * El resultado de intentar emparejar una linea del fichero con alguien que ya
 * esta en la plantilla (**RF-GP-05**).
 *
 * ## Por que no basta con devolver `?Employee`
 *
 * Porque el intento tiene **tres** desenlaces y no dos: se encontro a la
 * persona, no se encontro a nadie —y entonces es un alta— o **se encontro un
 * impedimento**: el correo de la linea pertenece a otra persona distinta de la
 * que dice su documento.
 *
 * Con un `?Employee`, ese tercer caso solo cabia como `null`, es decir, «da de
 * alta» — que es exactamente el fallo que la revision de la 5.5 encontro: la
 * fila se escribia y chocaba con el indice unico parcial de `employees.email` en
 * la fase de aplicacion, reventando el lote entero con un `409` que no nombraba
 * ninguna linea.
 *
 * ## Es de `Application` y no de `Domain`
 *
 * Porque transporta un {@see Employee} ya cargado del repositorio: es el
 * resultado de una consulta, no una regla. Vive junto al caso de uso que lo
 * produce, como {@see CompletedSetup} o {@see RegisteredEmployee}.
 */
final readonly class ImportMatch
{
    /**
     * @param  list<ImportMessage>  $errors
     */
    private function __construct(
        public ?Employee $employee,
        public array $errors,
    ) {}

    /** Se encontro a la persona, o no habia ninguna y es un alta. */
    public static function of(?Employee $employee): self
    {
        return new self($employee, []);
    }

    /** El emparejamiento encontro un impedimento: la linea no se puede aplicar. */
    public static function rejected(ImportMessage ...$errors): self
    {
        return new self(null, array_values($errors));
    }
}
