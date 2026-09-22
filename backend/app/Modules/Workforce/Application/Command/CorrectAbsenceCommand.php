<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/**
 * Lo que hace falta para corregir una ausencia (**RF-GP-04**, **RN-13**).
 *
 * **Todo es opcional menos el motivo.** Los campos omitidos conservan su valor:
 * una correccion que obligara a reenviar el objeto entero convertiria cualquier
 * cambio parcial en una oportunidad de pisar sin querer lo que no se tocaba.
 *
 * **`note` necesita dos campos.** `noteGiven` a `false` significa «no la
 * toques»; a `true` con `note` a `null` significa «borrala». Con un solo campo
 * seria imposible vaciar una nota, que es justamente lo que hay que poder hacer
 * cuando alguien escribio ahi algo que no debia.
 *
 * **No lleva `employeeUuid`.** Corregir a quien pertenece una ausencia no es
 * corregirla: es anular esta y registrar otra, y ese es el rastro correcto de
 * que hubo dos personas implicadas.
 */
final readonly class CorrectAbsenceCommand
{
    public function __construct(
        /** UUID de la version que se corrige. Tiene que ser la **vigente**. */
        public string $absenceUuid,
        /** Por que se corrige. Obligatorio (RN-13), texto libre de 3 a 500. */
        public string $reason,
        public ?AbsenceType $type = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public ?string $note = null,
        /** Si la peticion traia el campo `note`, aunque fuera a `null`. */
        public bool $noteGiven = false,
        public ?int $correctedByUserId = null,
    ) {}
}
