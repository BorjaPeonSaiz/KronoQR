<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use DateTimeImmutable;

/**
 * La tabla `license` (doc 01 §5): una fila o ninguna.
 *
 * ## Una fila
 *
 * Hay una licencia por instalacion, como hay un centro (ADR-040). El esquema lo
 * garantiza con un indice unico sobre una expresion constante, no con la buena
 * voluntad de este adaptador.
 *
 * ## Activar sustituye, y el historial vive en `audit_log`
 *
 * Renovar escribe encima de la fila. Puede parecer que choca con la regla dura 5
 * —nada se borra ni se sobrescribe— y no lo hace: esa regla protege el
 * **registro horario** y sus correcciones, que es lo que tiene valor probatorio.
 * La licencia es estado comercial del producto, y su historia —cada activacion,
 * con actor, momento, plan y limites— queda en `audit_log`, que si es solo
 * apendice y encadenado. Conservar aqui tambien las claves antiguas daria una
 * segunda fuente de verdad sobre cual esta vigente, que es peor.
 */
interface LicenseRepository
{
    /** La licencia activada, o `null` si esta instalacion no tiene ninguna. */
    public function current(): ?StoredLicense;

    /**
     * Sustituye la licencia activada por esta.
     *
     * Guarda la clave firmada entera —es la afirmacion con valor— y ademas sus
     * campos descompuestos como proyeccion legible, para que una consulta de
     * diagnostico no tenga que verificar nada.
     */
    public function activate(
        string $signedKey,
        License $license,
        DateTimeImmutable $activatedAt,
        ?int $actorUserId,
    ): void;

    /**
     * Anota que la clave se ha vuelto a verificar (ADR-018: `last_verified_at`).
     *
     * **Solo lo llaman los caminos que verifican a proposito**: la activacion,
     * `GET /api/v1/license` y `license:show`. El `FeatureGate` no lo llama, y esa
     * es la decision importante: si lo hiciera, cada pantalla del panel
     * escribiria en la tabla de licencia, y el camino de una lectura pasaria a
     * necesitar permiso de escritura.
     */
    public function markVerified(DateTimeImmutable $verifiedAt): void;
}
