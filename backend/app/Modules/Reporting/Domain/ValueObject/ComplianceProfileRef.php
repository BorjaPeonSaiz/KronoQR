<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * El perfil de cumplimiento con el que se ha evaluado, **solo para nombrarlo**:
 * «12 h segun el perfil ES-hosteleria» (RF-PA-06, paso 5 de la ficha).
 *
 * Los umbrales no estan aqui: van en `meta.rules[]`, ya resueltos a minutos por
 * el puerto `CompliancePolicyProvider` (regla dura 14). Esto responde a otra
 * pregunta —«¿de donde sale ese 12?»— y la respuesta tiene que poder darse sin
 * abrir la pantalla del perfil.
 */
final readonly class ComplianceProfileRef
{
    public function __construct(
        public int $id,
        public string $name,
        /** Jurisdiccion que declara el perfil (`ES`). */
        public string $jurisdiction,
    ) {}
}
