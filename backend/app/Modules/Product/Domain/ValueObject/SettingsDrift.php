<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Las claves en las que el `.env` y la base de datos dicen cosas distintas
 * (RF-PD-01, ADR-017).
 *
 * ## Por que es una clase y no dos bucles
 *
 * Porque la regla se consultaba desde dos sitios —la seccion `configuration`
 * del paquete de diagnostico y la sonda `settings.env_differs_from_db` de
 * `product:doctor`— y estaba escrita dos veces. Dos copias de la misma
 * comparacion divergen en cuanto alguien arregla un caso raro en una: el
 * informe que el cliente ve en su terminal y el que llega a soporte dentro del
 * paquete acabarian señalando claves distintas, y esa conversacion no lleva a
 * ningun sitio.
 *
 * ## Que compara, y que NO devuelve
 *
 * **Solo la clave.** Nunca los dos valores. Con los valores, la seccion
 * `configuration` del paquete se convertiria en una puerta trasera para sacar
 * del `.env` cualquier variable que ademas fuera una clave del catalogo,
 * esquivando la lista de permitidos `DiagnosticsConfigurationAllowlist` que la
 * filtra. Con la clave basta para decirle al cliente donde mirar.
 *
 * ## Manda la base de datos
 *
 * Decision de la tarea 5.1 (ADR-017): lo que se edita desde el panel es lo que
 * rige, y el `.env` se queda ahi engañando a quien lo lea. Por eso esto es un
 * **aviso** y no un fallo: no hay nada roto, pero es la explicacion de la mitad
 * de los «pues yo lo tengo puesto a otra cosa».
 *
 * ## Una clave ausente del entorno no es una diferencia
 *
 * Lo normal es que el `.env` no mencione la mayoria del catalogo: eso significa
 * «no opino», no «opino algo distinto». Solo se comparan las que estan.
 */
final readonly class SettingsDrift
{
    /**
     * @param  list<string>  $keys  Claves con valor distinto, en el orden del catalogo.
     */
    private function __construct(public array $keys) {}

    /**
     * @param  array<string, mixed>  $environment  El entorno del proceso.
     */
    public static function between(array $environment, ResolvedSettings $settings): self
    {
        $keys = [];

        foreach (SettingKey::cases() as $key) {
            $declared = $environment[$key->value] ?? null;

            if (! is_scalar($declared)) {
                continue;
            }

            if (self::textOf($settings->get($key)->value()) !== self::scalarText($declared)) {
                $keys[] = $key->value;
            }
        }

        return new self($keys);
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /**
     * La forma con la que la diferencia viaja en el paquete: clave y nada mas.
     *
     * @return list<array{key: string, differs: true}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (string $key): array => ['key' => $key, 'differs' => true],
            $this->keys,
        );
    }

    /**
     * Las claves separadas por coma, para el texto de `doctor`.
     */
    public function asText(): string
    {
        return implode(', ', $this->keys);
    }

    /**
     * El valor efectivo como texto comparable.
     *
     * Una lista se compara por su forma en el `.env` —separada por comas, que es
     * como se escribe `APP_SUPPORTED_LOCALES`—, porque si no toda clave de lista
     * apareceria como distinta siempre y el aviso se volveria ruido.
     *
     * @param  int|string|list<string>  $value
     */
    private static function textOf(int|string|array $value): string
    {
        return is_array($value) ? implode(',', $value) : (string) $value;
    }

    /**
     * El valor declarado en el entorno, como texto.
     *
     * `true`/`false` se comparan con esas palabras, que es lo que el `.env`
     * contiene: un `(string) true` daria `"1"` y marcaria como distinta una
     * clave que vale exactamente lo mismo.
     */
    private static function scalarText(bool|float|int|string $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
