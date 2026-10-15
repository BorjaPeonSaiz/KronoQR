<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Ya hay registro horario y se ha pedido otra zona horaria para el centro
 * (RN-23, ADR-056).
 *
 * **Es un `409` y no un `422`**: la peticion es valida en si misma —la zona
 * existe— y lo que no encaja es el estado de la instalacion. Ante esto no sirve
 * corregir el formulario: se relee el centro, que dira `timezone_locked: true`.
 * Sale con su propio `type`, `urn:kronoqr:problem:site-time-zone-locked`, para
 * que el panel no tenga que adivinarlo por el texto.
 *
 * **Se lanza antes de guardar nada.** Si la misma peticion traia un nombre
 * nuevo, tampoco se aplica: una peticion se acepta entera o no se acepta.
 *
 * Mismo criterio que `InvalidEmploymentPeriod`: `getMessage()` es tecnico y en
 * ingles —va al log y al historico de errores, sin datos personales (regla dura
 * 21)—; lo que ve una persona sale de {@see self::$translationKey} y
 * {@see self::$parameters}, que el borde HTTP resuelve en el idioma negociado.
 */
final class SiteTimezoneLocked extends WorkforceConflict
{
    public const string TRANSLATION_KEY = 'site.errors.timezone_locked';

    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $currentTimezone, string $requestedTimezone): self
    {
        return new self(
            self::TRANSLATION_KEY,
            ['timezone' => $currentTimezone],
            sprintf(
                'The site time zone is locked by the work record: it cannot change from %s to %s by the API (RN-23).',
                $currentTimezone,
                $requestedTimezone,
            ),
        );
    }
}
