<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use InvalidArgumentException;

/**
 * La orden de generar la exportacion para la Inspeccion (RF-IN-05).
 *
 * `destinationPath` la decide quien llama y no el caso de uso: el endpoint usa
 * un temporal que se borra al enviarlo y el comando de consola escribe donde le
 * digan, porque un requerimiento se contesta adjuntando un fichero que alguien
 * tiene que poder encontrar. Elegirla aqui dentro obligaria al caso de uso a
 * saber si lo esta invocando una peticion HTTP o una terminal.
 */
final readonly class GenerateLegalExportCommand
{
    public function __construct(
        public LegalExportPeriod $period,
        public LegalExportScope $scope,
        /** Ruta absoluta del fichero a escribir. */
        public string $destinationPath,
        /**
         * La cuenta que firma la exportacion pedida por consola (doc 01 §7.2,
         * «Firmas por consola»): el caso de uso la vuelve a comprobar con la
         * cadena tomada y el asiento la nombra como actor. Nulo por el panel,
         * donde el actor es la sesion de la peticion.
         */
        public ?int $signedByUserId = null,
    ) {
        if ($destinationPath === '') {
            throw new InvalidArgumentException('A legal export needs a destination path.');
        }

        if ($signedByUserId !== null && $signedByUserId < 1) {
            throw new InvalidArgumentException('A signed legal export needs the account that has signed it.');
        }
    }
}
