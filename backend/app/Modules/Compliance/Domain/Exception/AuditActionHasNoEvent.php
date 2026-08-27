<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Se ha añadido una accion al catalogo sin decir a que familia del bloque D
 * pertenece (`/revision-cumplimiento`, regla dura 6).
 *
 * Falla cerrado a proposito. La alternativa —devolver una familia por defecto—
 * dejaria pasar una accion auditable sin clasificar, y el bloque D existe
 * precisamente para que ninguna se quede sin clasificar. `AuditActionCatalogTest`
 * recorre el catalogo entero, asi que este error aparece en la suite unitaria y
 * no en produccion.
 */
final class AuditActionHasNoEvent extends ComplianceDomainException
{
    public function __construct(string $action, string $subject)
    {
        parent::__construct(sprintf(
            'La accion de auditoria «%s» tiene el sujeto «%s», que no esta en la tabla de familias del bloque D. '
            .'Añadelo a AuditAction::EVENT_BY_SUBJECT indicando a que familia pertenece.',
            $action,
            $subject,
        ));
    }
}
