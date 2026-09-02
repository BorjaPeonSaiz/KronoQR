<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Una funcionalidad **accesoria** no esta disponible con la licencia de esta
 * instalacion (**ADR-019**, ADR-023, RF-PD-05).
 *
 * ## Lo primero: esto NUNCA lo lanza nada del registro legal
 *
 * Solo se construye a partir de un {@see Feature}, y en ese enum no existe el
 * fichaje, ni la consulta de jornadas, ni el portal, ni la exportacion para la
 * Inspeccion, ni la auditoria, ni las copias. **No es una convencion: es que no
 * hay forma de escribirlo.** Si algun dia esta excepcion apareciera en el camino
 * de `POST /api/v1/scan`, seria porque alguien añadio un caso al enum que ADR-023
 * prohibe, y la prueba de arquitectura falla antes.
 *
 * ## Por que una excepcion y no un `403`
 *
 * ADR-019 lo pide por escrito en su verificacion: *«cada funcionalidad accesoria
 * responde con el aviso de licencia y no con un error generico»*. Un `403`
 * mezclaria «no tienes permiso» con «tu empresa no ha renovado», que son dos
 * problemas de dos personas distintas: el primero lo arregla quien administra
 * los roles y el segundo quien firma el contrato. En un log, ademas, serian
 * indistinguibles.
 *
 * El borde la traduce a `402` con `application/problem+json` y un `type` propio,
 * de modo que el panel puede reconocerla sin leer el texto y enseñar el aviso
 * con el boton de «ver el estado de la licencia» en vez de una pantalla de
 * error.
 *
 * ## Lleva el porque y el desde cuando
 *
 * Que es lo que hace honesta a la degradacion. El «que hacer» lo compone el
 * borde en el idioma negociado con `lang/{es,en}/license.php`.
 */
final class FeatureNotLicensed extends RuntimeException
{
    private function __construct(
        public readonly Feature $feature,
        public readonly ?FeatureRestriction $restriction,
        public readonly ?DateTimeImmutable $since,
    ) {
        parent::__construct(\sprintf(
            'The accessory feature "%s" is not available: %s.',
            $feature->value,
            $restriction->value ?? 'unknown',
        ));
    }

    public static function from(FeatureAvailability $availability): self
    {
        return new self($availability->feature, $availability->restriction, $availability->since);
    }

    /**
     * Clave de traduccion del texto que lee una persona.
     *
     * Se compone con el motivo y no con la funcionalidad: lo que hay que hacer
     * depende de por que no esta —renovar, activar, pedir otra clave, ampliar el
     * plan—, no de cual sea. El nombre de la funcionalidad entra como parametro.
     */
    public function translationKey(): string
    {
        return 'license.unavailable.'.($this->restriction->value ?? 'unknown');
    }

    /**
     * El instante desde el que esta degradada, **en UTC y en forma canonica**.
     *
     * ## Por que no se formatea aqui
     *
     * Porque `Domain/` no sabe en que idioma se va a leer. La version anterior
     * escribia `d/m/Y` fijo, asi que el mensaje ingles salia con la fecha en
     * formato español: *«unavailable because the licence expired on 31/12/2026»*.
     * Es el mismo motivo por el que el texto vive en `lang/` y no aqui.
     *
     * Quien resuelve la traduccion —`ProblemDetails::translated()`, en el
     * borde— es quien conoce el idioma negociado y da forma a la fecha. Aqui va
     * el dato, no su presentacion.
     */
    public function sinceUtc(): ?string
    {
        return $this->since?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Los parametros del mensaje traducido.
     *
     * `since` llega **ya formateado por el borde**, en el idioma negociado.
     *
     * @param  string  $since  la fecha tal y como la va a leer una persona
     * @return array<string, string|int>
     */
    public function parameters(string $since): array
    {
        return [
            'feature' => $this->feature->value,
            'since' => $since,
        ];
    }
}
