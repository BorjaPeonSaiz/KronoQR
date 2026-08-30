<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que hay —o lo que se ha ido— en un almacen concreto (RF-PR-03).
 *
 * Es la fila del informe: **ambito, almacen, cuantas filas y que rango**. Ni una
 * sola columna con datos personales (regla dura 21): el informe de purga se
 * queda en el servidor del cliente, pero se lee, se archiva y se adjunta a
 * cualquier reclamacion, y para responder «que se borro» bastan un recuento y
 * dos fechas.
 *
 * `available` distingue «no habia nada que purgar» de «ese almacen todavia no
 * existe en esta instalacion». Hoy solo lo usa `error_events`, que llega con la
 * tarea 5.12: un informe que dijera «0 filas» de una tabla inexistente afirmaria
 * que el ciclo corto de RL-11 esta corriendo cuando no lo esta.
 */
final readonly class RetentionTally
{
    public function __construct(
        public RetentionScope $scope,
        /** Tabla, particion o ruta relativa. Nunca una ruta absoluta del servidor. */
        public string $dataset,
        public int $rows,
        /** Extremos del rango afectado, `YYYY-MM-DD` o `null` si no hay filas. */
        public ?string $oldest = null,
        public ?string $newest = null,
        public bool $available = true,
    ) {}

    public static function unavailable(RetentionScope $scope, string $dataset): self
    {
        return new self($scope, $dataset, 0, null, null, false);
    }

    public function isEmpty(): bool
    {
        return $this->rows === 0;
    }

    public function range(): string
    {
        if (! $this->available) {
            return 'no instalado';
        }

        if ($this->oldest === null || $this->newest === null) {
            return '—';
        }

        return $this->oldest.' → '.$this->newest;
    }
}
