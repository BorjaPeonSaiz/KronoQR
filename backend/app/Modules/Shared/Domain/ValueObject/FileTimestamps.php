<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Las dos marcas de tiempo de una entrada del disco, y cual cuenta como «ultimo
 * toque» (ADR-045, condicion C9).
 *
 * ## Por que no basta con `mtime`
 *
 * `mtime` lo pone cualquiera con `touch -d`, y una herramienta de copia que
 * conserva fechas lo trae del pasado: un fichero recien restaurado pareceria
 * viejo y se borraria en la primera pasada. `ctime` no se puede fijar desde
 * fuera —cambia con cualquier escritura o cambio de permisos—, asi que la edad
 * es la del **mas reciente de los dos**.
 *
 * ## Y si `mtime` esta en el futuro, cuenta `ctime`
 *
 * Un `mtime` futuro haria al fichero inmortal: su edad seria negativa hasta esa
 * fecha. Puede venir de un reloj desajustado al copiar o de alguien que quiere
 * que un fichero no se borre nunca. En ese caso se ignora y se usa `ctime`.
 *
 * Sin reloj propio (regla dura 2): el instante de referencia llega de quien lo
 * tiene resuelto por el puerto `Clock`.
 */
final readonly class FileTimestamps
{
    public function __construct(
        public DateTimeImmutable $modifiedAt,
        public DateTimeImmutable $changedAt,
    ) {}

    public static function fromEpochSeconds(int $modified, int $changed): self
    {
        // `@<segundos>` y no `new DateTimeImmutable()` + `setTimestamp()`: lo
        // segundo lee el reloj de la maquina antes de pisarlo (regla dura 2).
        return new self(
            new DateTimeImmutable('@'.$modified),
            new DateTimeImmutable('@'.$changed),
        );
    }

    public function lastTouchedAt(DateTimeImmutable $now): DateTimeImmutable
    {
        if ($this->modifiedAt > $now) {
            return $this->changedAt;
        }

        return $this->modifiedAt > $this->changedAt ? $this->modifiedAt : $this->changedAt;
    }
}
