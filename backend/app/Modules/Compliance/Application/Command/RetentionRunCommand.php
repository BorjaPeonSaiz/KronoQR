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
         * Cuenta de gestion que autoriza la purga y firma el asiento, con rol
         * admin o rrhh. **Obligatoria para ejecutar desde la 2.3.0** (doc 01
         * RF-PR-03, §7.2 «Firmas por consola»): solo puede ser `null` en la
         * simulacion, que no escribe asiento.
         */
        public ?int $responsibleUserId,
        /** Filas por sentencia de borrado. Acota el tamano, no la transaccion. */
        public int $batchSize,
    ) {}

    /**
     * `$responsibleUserId` solo para comprobar ya en la simulacion que la cuenta
     * existe y esta activa: la simulacion no escribe asiento.
     */
    public static function simulate(int $batchSize = 1000, ?int $responsibleUserId = null): self
    {
        return new self(RetentionMode::Simulation, null, $responsibleUserId, $batchSize);
    }

    public static function execute(string $confirmation, int $responsibleUserId, int $batchSize = 1000): self
    {
        return new self(RetentionMode::Execution, $confirmation, $responsibleUserId, $batchSize);
    }

    public function isSimulation(): bool
    {
        return $this->mode->isSimulation();
    }
}
