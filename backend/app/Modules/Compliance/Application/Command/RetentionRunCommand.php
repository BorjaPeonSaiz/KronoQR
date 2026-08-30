<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\RetentionMode;

/**
 * La orden de una pasada de retencion (RF-PR-03).
 *
 * **El modo por defecto es simular.** No hay forma de construir esta orden en
 * modo destructivo sin frase de confirmacion, y esa es la mitad de la
 * salvaguarda; la otra mitad es que la frase la imprime la simulacion y no se
 * puede deducir.
 */
final readonly class RetentionRunCommand
{
    private function __construct(
        public RetentionMode $mode,
        /** La frase que imprimio el `--dry-run`; `null` en simulacion. */
        public ?string $confirmation,
        /**
         * Cuenta de gestion que autoriza la purga, para el asiento. `null`
         * cuando la lanza quien tiene acceso al servidor sin sesion en el panel:
         * el actor es entonces `system`, que es la verdad y se cruza con el
         * registro de acceso a la maquina.
         */
        public ?int $responsibleUserId,
        /** Filas por sentencia de borrado. Acota el tamano, no la transaccion. */
        public int $batchSize,
    ) {}

    public static function simulate(int $batchSize = 1000): self
    {
        return new self(RetentionMode::Simulation, null, null, $batchSize);
    }

    public static function execute(string $confirmation, ?int $responsibleUserId, int $batchSize = 1000): self
    {
        return new self(RetentionMode::Execution, $confirmation, $responsibleUserId, $batchSize);
    }

    public function isSimulation(): bool
    {
        return $this->mode->isSimulation();
    }
}
