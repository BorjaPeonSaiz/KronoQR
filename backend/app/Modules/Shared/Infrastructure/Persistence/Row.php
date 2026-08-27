<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Lectura tipada de una fila devuelta por el controlador de consultas.
 *
 * **Por que existe.** `ConnectionInterface::select()` devuelve objetos cuyos
 * valores son `mixed`, y el driver de PostgreSQL no promete el tipo PHP de cada
 * columna: un `integer` puede llegar como `int` o como `string` segun como este
 * compilado PDO. Escribir `(int) $row->version` disperso por el adaptador
 * funciona hasta que una columna nula pasa por un `(int)` y se convierte en un
 * cero que parece un dato.
 *
 * Aqui la conversion esta en un sitio, distingue **nulo** de **cero** y de
 * **cadena vacia**, y falla en voz alta cuando una columna obligatoria no viene:
 * en un registro con valor legal, un cero silencioso es peor que una excepcion.
 *
 * Todos los instantes salen en **UTC** (regla dura 3). La conversion a la zona
 * del centro ocurre en la capa de presentacion, no aqui.
 *
 * **Por que en `Shared` y no en el modulo que lo estreno.** Nacio en
 * `Reporting/Infrastructure/Persistence` para el lector del registro horario,
 * pero el problema que resuelve no es de ese modulo: es de PDO. Mientras vivio
 * alli, la exportacion legal de `Compliance` —que no puede importar de
 * `Reporting` (doc 02 §1.6)— seguia haciendo `(int) $row->day_minutes` a mano
 * sobre el total de una jornada que acaba en un documento con efectos legales,
 * apoyandose en una anotacion `@var` que PHPStan cree y el driver no garantiza.
 * Aqui lo alcanzan los ocho modulos y esa segunda copia deja de existir.
 */
final readonly class Row
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private array $values) {}

    public static function of(object $row): self
    {
        return new self(get_object_vars($row));
    }

    public function string(string $column): string
    {
        $value = $this->values[$column] ?? null;

        if (! is_scalar($value)) {
            throw new RuntimeException('La columna «'.$column.'» no trae un valor de texto.');
        }

        return (string) $value;
    }

    public function nullableString(string $column): ?string
    {
        return ($this->values[$column] ?? null) === null ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->values[$column] ?? null;

        if (! is_numeric($value)) {
            throw new RuntimeException('La columna «'.$column.'» no trae un numero.');
        }

        return (int) $value;
    }

    public function nullableInt(string $column): ?int
    {
        return ($this->values[$column] ?? null) === null ? null : $this->int($column);
    }

    /**
     * `boolean` de PostgreSQL, que puede llegar como `bool`, como `'t'`, como
     * `'true'` o como `'1'` segun el driver y su version.
     */
    public function bool(string $column): bool
    {
        $value = $this->values[$column] ?? null;

        return in_array($value, [true, 't', 'true', 1, '1'], true);
    }

    public function instant(string $column): DateTimeImmutable
    {
        $instant = $this->nullableInstant($column);

        if (! $instant instanceof DateTimeImmutable) {
            throw new RuntimeException('La columna «'.$column.'» no trae ningun instante.');
        }

        return $instant;
    }

    public function nullableInstant(string $column): ?DateTimeImmutable
    {
        $value = $this->values[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value)) {
            throw new RuntimeException('La columna «'.$column.'» no trae una marca de tiempo legible.');
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Columna `JSONB` decodificada. Nula cuando la columna lo es, que en
     * `shift_corrections.before` / `.after` es **significativo**: en un alta no
     * hay valor anterior y en una anulacion no hay posterior.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $column): ?array
    {
        $value = $this->values[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException('La columna «'.$column.'» no trae un documento JSON.');
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('La columna «'.$column.'» no trae un objeto JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
