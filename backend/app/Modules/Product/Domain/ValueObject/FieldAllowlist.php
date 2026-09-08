<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El anonimizador del paquete de diagnostico: **lista de permitidos, nunca de
 * exclusiones** (RF-PD-09, RL-19, ADR-020, regla dura 21).
 *
 * ## Por que no es una lista de exclusiones
 *
 * Es la decision central de la tarea y la escribe la ficha 5.9 con todas las
 * letras: *«una lista de exclusiones falla en silencio cada vez que se añade un
 * campo nuevo»*. Con exclusiones, el dia que alguien añada `employees.nickname`
 * el paquete empieza a llevar apodos hacia el fabricante y nadie se entera; con
 * permitidos, ese campo simplemente no aparece hasta que alguien lo añada aqui a
 * mano, mirandolo.
 *
 * ## Lo que ademas hace, y por lo que no es un `array_intersect_key`
 *
 * 1. **Impone el orden declarado**, para que dos paquetes de la misma
 *    instalacion se puedan comparar linea a linea.
 * 2. **Rellena con `null` lo permitido que falta**, para que soporte distinga
 *    «esa columna no tenia valor» de «esa columna no viaja».
 * 3. **Corta las estructuras anidadas**: un valor que es a su vez un mapa no
 *    entra, porque la lista no puede afirmar nada sobre sus claves. Sin esta
 *    regla, permitir un campo `meta` colaria el objeto entero que llevara
 *    dentro — que es exactamente como se filtra un `client_meta`.
 */
final readonly class FieldAllowlist
{
    /** @var list<string> */
    public array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        $allowed = [];

        foreach ($this->fields as $field) {
            $value = $row[$field] ?? null;

            // Un mapa anidado no entra: la lista no dice nada sobre sus claves,
            // asi que permitirlo seria permitir lo que aun no existe. Una lista
            // de escalares si —`features` de la licencia es una— porque no tiene
            // claves que ocultar nada.
            $allowed[$field] = is_array($value) && ! array_is_list($value) ? null : $value;
        }

        return $allowed;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function applyAll(iterable $rows): array
    {
        $allowed = [];

        foreach ($rows as $row) {
            $allowed[] = $this->apply($row);
        }

        return $allowed;
    }

    public function allows(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
