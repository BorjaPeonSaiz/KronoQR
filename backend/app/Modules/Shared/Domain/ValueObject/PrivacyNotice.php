<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Lo que el **aviso de privacidad del quiosco** necesita del cliente (RF-KI-09,
 * RL-09, art. 13 RGPD en capa 1): quien es el responsable del tratamiento y
 * donde esta la politica completa.
 *
 * `null` en cualquiera de los dos es «sin configurar» y el quiosco enseña la
 * redaccion generica: **el aviso no desaparece nunca**. Por eso tampoco se
 * degrada con la licencia (ADR-019): la marca blanca puede perder el logotipo y
 * el color, pero el aviso legal se sirve siempre con lo configurado.
 *
 * Vive en `Shared` porque viaja dentro de {@see Branding}, que leen varios
 * modulos. Ni la forma ni el contenido se validan aqui: lo hace el catalogo de
 * ajustes al guardar (`SettingKey::PRIVACY_*`), que es la unica puerta.
 */
final readonly class PrivacyNotice
{
    public function __construct(
        public ?string $controllerName = null,
        public ?string $policyUrl = null,
    ) {}

    /** La redaccion generica: nada configurado. */
    public static function generic(): self
    {
        return new self(null, null);
    }
}
