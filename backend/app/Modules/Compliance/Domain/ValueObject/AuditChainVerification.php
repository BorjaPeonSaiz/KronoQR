<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El resultado de recorrer la cadena entera (RS-07).
 *
 * Tres cifras y una lista:
 *
 * - `rowsVerified` — cuantas filas se han recomputado. Sirve para notar que el
 *   verificador se quedo corto: una cadena que crece y un recuento que no, es un
 *   sintoma por si mismo.
 * - `sealedPurgeYears` — las purgas legitimas que se han reconocido por su ancla
 *   (ADR-027). No son roturas: se informan.
 * - `breaks` — lo que si es una rotura. **Cualquier elemento aqui dispara la
 *   alerta critica de seguridad**, sin umbral: el catalogo del doc 01 §9.3 dice
 *   «cualquiera».
 * - `unknownActions` — nombres de accion que esta version no tiene en su
 *   catalogo. **Tampoco son roturas**: la cadena se verifica por hash y el hash
 *   se recalcula con la cadena literal, asi que una fila con una accion
 *   desconocida verifica igual de bien. Se informan porque dicen algo util —esta
 *   base la escribio una version mas nueva que la que esta corriendo, tipicamente
 *   tras una vuelta atras del actualizador— y porque, sin nombrarlas, ese dato se
 *   perderia. Ver {@see AuditActionName}.
 */
final readonly class AuditChainVerification
{
    /**
     * @param  list<AuditChainBreak>  $breaks
     * @param  list<int>  $sealedPurgeYears
     * @param  list<string>  $unknownActions
     */
    public function __construct(
        public int $rowsVerified,
        public array $breaks,
        public array $sealedPurgeYears = [],
        public array $unknownActions = [],
    ) {}

    /**
     * ¿Hay alguna fila con una accion que esta version no conoce?
     */
    public function sawUnknownActions(): bool
    {
        return $this->unknownActions !== [];
    }

    public function isIntact(): bool
    {
        return $this->breaks === [];
    }

    public function failureCount(): int
    {
        return \count($this->breaks);
    }
}
