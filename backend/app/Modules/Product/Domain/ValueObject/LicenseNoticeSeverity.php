<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Con que tono se enseña el aviso de licencia.
 *
 * Existe para que el panel, `GET /api/v1/license` y `license:show` no repitan
 * cada uno su propia tabla de «esto es grave y esto no». Tres valores:
 *
 * - `None` — no hay nada que decir. Licencia vigente y fuera de la ventana de aviso.
 * - `Warning` — hay que actuar, todavia no se ha perdido nada. Caduca pronto, o
 *   su vigencia aun no ha empezado, o se ha superado una cifra del plan.
 * - `Critical` — ya se ha perdido algo accesorio. Caducada, ausente o ilegible.
 *
 * **`Critical` nunca significa que el sistema este parado.** El fichaje, la
 * consulta del registro, la exportacion para la Inspeccion, el portal y las
 * copias funcionan igual en los tres niveles (regla dura 15). Lo que cambia es
 * el color del banner y la urgencia del texto.
 */
enum LicenseNoticeSeverity: string
{
    case None = 'none';
    case Warning = 'warning';
    case Critical = 'critical';
}
