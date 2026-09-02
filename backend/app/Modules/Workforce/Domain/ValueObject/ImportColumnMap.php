<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Que cabecera del fichero corresponde a que campo del producto (**RF-GP-05**,
 * regla dura 13).
 *
 * ## Es configuracion, y por eso este objeto se construye con datos
 *
 * El fichero que un hotel saca de su sistema anterior trae las columnas que
 * trae. Si adaptarse a un cliente exigiera tocar el repositorio, este importador
 * seria una consultoria encubierta (ADR-017). Los alias de serie —espanol e
 * ingles— viven en `config/workforce.php` y el cliente añade los suyos con
 * `WORKFORCE_IMPORT_COLUMN_ALIASES` en su `.env`.
 *
 * ## La comparacion es tolerante a proposito
 *
 * Minusculas, sin acentos, con los separadores unificados y sin espacios de
 * sobra: `Fecha de alta`, `fecha_de_alta` y `FECHA DE ALTA` son la misma
 * columna. La alternativa —exigir la cabecera exacta— convierte un espacio de
 * mas en un «no encuentro la columna» que nadie sabe leer, y ese espacio lo pone
 * cualquier exportacion.
 *
 * ## Lo que NO se puede mapear
 *
 * `employee_code`. No es un campo del fichero: el codigo es opaco y lo genera el
 * servidor (doc 01 §5.5). Un alias para el permitiria meter en una tarjeta
 * impresa el numero de nomina del sistema anterior.
 */
final readonly class ImportColumnMap
{
    /**
     * @param  array<string, string>  $fieldByHeader  Cabecera normalizada -> campo.
     */
    private function __construct(private array $fieldByHeader) {}

    /**
     * @param  array<string, list<string>>  $aliasesByField
     */
    public static function of(array $aliasesByField): self
    {
        $fieldByHeader = [];

        foreach ($aliasesByField as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalised = self::normalise($alias);

                if ($normalised !== '') {
                    // El PRIMERO gana: los alias de serie se declaran antes que
                    // los del cliente, asi que un alias propio no puede robarle
                    // una cabecera estandar a otro campo por descuido.
                    $fieldByHeader[$normalised] ??= $field;
                }
            }
        }

        return new self($fieldByHeader);
    }

    /** El campo al que corresponde esa cabecera, o `null` si no se reconoce. */
    public function fieldFor(string $header): ?string
    {
        return $this->fieldByHeader[self::normalise($header)] ?? null;
    }

    /**
     * Normaliza una cabecera para compararla: sin acentos, en minusculas, con
     * cualquier separador convertido en `_` y sin repetirlos.
     *
     * `iconv` no esta disponible en todas las compilaciones, asi que la
     * transliteracion se hace con una tabla explicita: es corta, cubre el
     * castellano y el gallego —que es donde estan los clientes— y no depende de
     * como este compilado el PHP del servidor del hotel.
     */
    public static function normalise(string $header): string
    {
        $lower = mb_strtolower(trim($header));

        $withoutAccents = strtr($lower, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $separated = preg_replace('/[^a-z0-9]+/', '_', $withoutAccents);

        return trim(\is_string($separated) ? $separated : $withoutAccents, '_');
    }
}
