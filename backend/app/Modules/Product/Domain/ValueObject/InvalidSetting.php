<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidSettingValue;

/**
 * Una fila guardada cuyo valor su clave no admite, **vista desde la lectura**
 * (RF-PD-01, regla dura 19).
 *
 * ## Por que existe
 *
 * Leer la configuracion tiene que ser tolerante: se hace en el camino de
 * fichaje. Si una fila corrupta —editada a mano con `psql` durante una
 * intervencion, o escrita por una version distinta— hiciera fallar la lectura,
 * `POST /scan` respondería un error y **nadie podria fichar** por un color de
 * marca mal escrito. La cascada cae al valor de serie del catalogo y deja aqui
 * constancia de lo que ha descartado.
 *
 * Pero **descartar en silencio tampoco vale**: una clave `worked_hours` con un
 * valor corrupto cambia los minutos que se calculan, y quien mire la nomina
 * dentro de un mes no tendria forma de saberlo. Por eso esto viaja en
 * `meta.invalid_keys` de `GET /api/v1/settings`, se registra como `warning` y lo
 * enseñara `doctor` (tarea 5.9).
 *
 * ## Lleva la clave de traduccion, no el texto
 *
 * El dominio no sabe en que idioma se va a leer esto. Guarda la clave y sus
 * parametros —los mismos de {@see InvalidSettingValue}— y quien lo publica
 * resuelve `__()` en el idioma negociado.
 */
final readonly class InvalidSetting
{
    /**
     * @param  array<string, string|int>  $parameters
     */
    private function __construct(
        /** La clave del catalogo cuyo valor guardado se ha descartado. */
        public SettingKey $key,
        /** Clave de `lang/*\/settings.php` que explica el motivo. */
        public string $translationKey,
        /** @var array<string, string|int> */
        public array $parameters,
        /** Motivo en ingles y sin traducir, para el log tecnico. */
        public string $detail,
        /** Si descartarla puede cambiar los minutos del registro legal. */
        public bool $affectsWorkedHours,
    ) {}

    public static function from(SettingKey $key, InvalidSettingValue $rejection): self
    {
        return new self(
            key: $key,
            translationKey: $rejection->translationKey,
            parameters: $rejection->parameters,
            detail: $rejection->getMessage(),
            affectsWorkedHours: $key->definition()->impact->affectsWorkedHours(),
        );
    }

    /**
     * La incoherencia **entre claves**: el idioma por defecto guardado no esta
     * entre los disponibles.
     *
     * No es un valor mal formado —«es» es un idioma perfectamente valido—, sino
     * un conjunto imposible. Se anota contra `LOCALE_DEFAULT` porque es el valor
     * que deja de aplicarse.
     */
    public static function incoherentDefaultLocale(string $stored, string $effective, string $available): self
    {
        return new self(
            key: SettingKey::LOCALE_DEFAULT,
            translationKey: 'settings.errors.default_locale_not_available',
            parameters: ['key' => SettingKey::LOCALE_DEFAULT->value, 'default' => $stored, 'available' => $available],
            detail: 'Stored default locale "'.$stored.'" is not among the available locales ('
                .$available.'); "'.$effective.'" applies instead.',
            affectsWorkedHours: false,
        );
    }
}
