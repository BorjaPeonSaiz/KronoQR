<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Una entrada de la raiz de una clase de fichero, con lo necesario para decidir
 * si es lo bastante vieja para borrarla (ADR-045, C1, C9).
 *
 * ## La edad de un directorio es la de su entrada mas reciente
 *
 * Un `.work-<uuid>/` cuyo directorio se creo hace dos horas pero en el que se
 * escribio un CSV hace un minuto **esta en uso**. Por eso la entrada lleva las
 * marcas del propio directorio y las de cada fichero de su primer nivel, y la
 * edad es la del toque mas reciente de todas. Un fichero suelto lleva solo las
 * suyas.
 *
 * ## «Supera» y no «alcanza»
 *
 * Se borra cuando la edad es **estrictamente mayor** que el minimo de su clase
 * (doc 01 §5.5: «cuando su edad supera el minimo»). En el empate se conserva: la
 * edad minima existe para no competir nunca con un trabajo en curso, y un
 * segundo de mas no cuesta nada.
 *
 * ## Sin marcas, nunca es viejo
 *
 * Si el adaptador no pudo leer ninguna marca, la entrada se trata como recien
 * tocada. Borrar de mas en el disco de un cliente es peor que dejar un fichero
 * una pasada mas.
 */
final readonly class GeneratedFileEntry
{
    /**
     * @param  list<FileTimestamps>  $timestamps  Las de la propia entrada y, en un directorio,
     *                                            las de cada entrada de su primer nivel.
     */
    public function __construct(
        public string $name,
        public array $timestamps,
    ) {}

    public function lastTouchedAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $latest = null;

        foreach ($this->timestamps as $timestamps) {
            $touched = $timestamps->lastTouchedAt($now);

            if ($latest === null || $touched > $latest) {
                $latest = $touched;
            }
        }

        return $latest ?? $now;
    }

    /** Segundos desde el ultimo toque. Negativo si el ultimo toque esta en el futuro. */
    public function ageInSeconds(DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->lastTouchedAt($now)->getTimestamp();
    }

    public function isOlderThan(int $minimumAgeSeconds, DateTimeImmutable $now): bool
    {
        return $this->ageInSeconds($now) > $minimumAgeSeconds;
    }
}
