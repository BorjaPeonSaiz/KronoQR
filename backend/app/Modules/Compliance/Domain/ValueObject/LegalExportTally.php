<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Cuanto salio en la exportacion: tramos, correcciones y personas (RF-IN-05,
 * RS-05).
 *
 * **Es lo que convierte «alguien exporto» en «alguien se llevo la plantilla
 * entera».** Va al asiento de `audit_log` y a la salida del comando, y es el
 * unico dato que permite, meses despues, distinguir la consulta de una jornada
 * concreta de una descarga masiva. Sin cifras, el trail dice que hubo una
 * exportacion y no dice de que tamaño, que es justo lo que se pregunta ante una
 * brecha (RL-15).
 *
 * **Cuenta, no enumera.** No lleva la lista de `employee_uuid` exportados a
 * proposito: eso convertiria `audit_log` —cuatro años de retencion— en una
 * segunda copia de la plantilla (regla dura 21, minimizacion del RGPD).
 */
final readonly class LegalExportTally
{
    private function __construct(
        public int $shiftEntries,
        public int $corrections,
        public int $employees,
    ) {}

    public static function of(int $shiftEntries, int $corrections, int $employees): self
    {
        return new self($shiftEntries, $corrections, $employees);
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    /** Filas de datos del fichero, sin contar la cabecera ni los criterios. */
    public function rows(): int
    {
        return $this->shiftEntries + $this->corrections;
    }
}
