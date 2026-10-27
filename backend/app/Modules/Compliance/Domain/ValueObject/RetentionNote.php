<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una observacion del informe de retencion: lo que la pasada no ha podido hacer
 * y por que (RF-PR-03).
 *
 * Se guarda **que paso y con que cifras**, no la frase: el informe y la consola
 * la escriben en el idioma de la instalacion leyendo
 * `retention.report.observations.<kind>` con estos parametros. Sin datos
 * personales (regla dura 21): un ano de particion o un recuento, nunca un
 * `employee_uuid`.
 */
final readonly class RetentionNote
{
    /**
     * @param  array<string, int>  $parameters
     */
    private function __construct(
        public RetentionNoteKind $kind,
        public array $parameters,
    ) {}

    /** La particion del ano dado no tenia filas: no se sella ni se suelta. */
    public static function emptyAuditPartition(int $year): self
    {
        return new self(RetentionNoteKind::EmptyAuditPartition, ['year' => $year]);
    }

    /** Fotos de fichas minimizadas que no se pudieron borrar del disco. */
    public static function orphanedPersonnelPhotos(int $count): self
    {
        return new self(RetentionNoteKind::OrphanedPersonnelPhotos, ['count' => $count]);
    }
}
