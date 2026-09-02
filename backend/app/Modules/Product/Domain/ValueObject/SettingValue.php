<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidSettingValue;

/**
 * El valor de una clave de configuracion, ya validado y con tipo (RF-PD-01).
 *
 * **No se puede construir uno invalido.** El constructor es privado y las dos
 * fabricas pasan por la definicion de la clave: o el valor cumple lo que su
 * clave declara, o no hay objeto. Por eso quien recibe un `SettingValue` no
 * vuelve a comprobar nada, y por eso la validacion no esta duplicada en el
 * FormRequest, en el adaptador y en el caso de uso.
 *
 * **Lleva de donde sale.** `isProductDefault` distingue «el cliente puso 12» de
 * «nadie lo ha tocado y el producto vale 12». Son indistinguibles en el numero
 * y muy distintos en la conversacion: `GET /api/v1/settings` lo devuelve para
 * que el panel enseñe cual esta configurado, y el asiento de auditoria del
 * `PATCH` puede decir que un valor se establecio por primera vez.
 */
final readonly class SettingValue
{
    /**
     * @param  int|string|list<string>  $value
     */
    private function __construct(
        public SettingKey $key,
        private int|string|array $value,
        public bool $isProductDefault,
    ) {}

    /**
     * Un valor que viene de fuera —una fila de `installation_settings`, el
     * cuerpo de un `PATCH`—, validado contra el catalogo.
     */
    public static function of(SettingKey $key, mixed $raw): self
    {
        return new self($key, $key->definition()->validate($key, $raw), false);
    }

    /**
     * El valor de serie del producto.
     *
     * No se valida: el catalogo es codigo y `SettingCatalogTest` comprueba que
     * cada valor por defecto cumple su propia definicion. Revalidarlo aqui
     * convertiria un error del producto en una excepcion en casa del cliente.
     */
    public static function productDefault(SettingKey $key): self
    {
        return new self($key, $key->definition()->default, true);
    }

    /**
     * El valor tal como se guarda en `installation_settings.value` (JSONB) y
     * tal como viaja en la respuesta de la API.
     *
     * @return int|string|list<string>
     */
    public function value(): int|string|array
    {
        return $this->value;
    }

    public function asInteger(): int
    {
        if (! is_int($this->value)) {
            throw InvalidSettingValue::notAnInteger($this->key, get_debug_type($this->value));
        }

        return $this->value;
    }

    public function asText(): string
    {
        if (! is_string($this->value)) {
            throw InvalidSettingValue::notText($this->key, get_debug_type($this->value));
        }

        return $this->value;
    }

    /**
     * @return list<string>
     */
    public function asTextList(): array
    {
        if (! is_array($this->value)) {
            throw InvalidSettingValue::notAList($this->key, get_debug_type($this->value));
        }

        return $this->value;
    }

    /**
     * Si cambiar esta clave puede cambiar los minutos del registro legal.
     *
     * Lo pide el asiento de auditoria del `PATCH` (paso 8 de la tarea 5.1). El
     * matiz de las claves que solo alteran que incidencias se abren esta en
     * {@see SettingImpact}.
     */
    public function affectsWorkedHours(): bool
    {
        return $this->key->definition()->impact->affectsWorkedHours();
    }

    /**
     * Igualdad por clave y valor, **no por procedencia**.
     *
     * Es lo que necesita el `PATCH` para no escribir una fila ni un asiento de
     * auditoria cuando el administrador guarda la pantalla sin haber tocado
     * nada. Establecer explicitamente el valor que ya regia de serie si es un
     * cambio de estado —deja de depender del producto— y por eso lo distingue
     * `isProductDefault`, no `equals`.
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key && $this->value === $other->value;
    }
}
