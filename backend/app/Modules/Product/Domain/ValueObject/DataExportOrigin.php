<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Por donde se pidio la exportacion integra (**RF-PD-14**, tarea 5.10).
 *
 * ## Por que es un dato y no se deduce de `requested_by_user_id`
 *
 * Parece que si: la consola no tiene sesion, asi que «sin usuario» equivaldria a
 * «por consola». Pero las dos cosas se pueden separar —una cuenta borrada deja
 * `requested_by_user_id` en nulo (`nullOnDelete`)— y entonces una exportacion
 * pedida desde el panel pasaria a figurar como pedida por SSH. La distincion
 * importa: responde a «¿esto lo hizo alguien del hotel desde su navegador o
 * alguien con acceso al servidor?», que es la primera pregunta de cualquier
 * revision de un acceso masivo a datos personales (RS-05).
 */
enum DataExportOrigin: string
{
    /** `POST /api/v1/data-export`. Asincrona: encola y responde `202`. */
    case Panel = 'panel';

    /** `php artisan product:export-all`. Sincrona, en primer plano. */
    case Console = 'console';

    /**
     * El catalogo, para el `CHECK` de la migracion.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $origin): string => $origin->value, self::cases());
    }
}
