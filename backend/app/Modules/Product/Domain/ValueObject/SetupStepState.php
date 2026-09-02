<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * En que estado esta un paso del asistente (RF-PD-03).
 *
 * **`skipped` no es `pending`, y por eso son dos valores.** El asistente no se
 * puede cerrar con un paso en `pending` —seria terminar sin haberlo mirado— y si
 * con uno en `skipped`, que es una decision tomada y registrada. Sin la
 * distincion, «no he llegado ahi» y «lo he visto y lo dejo para cuando llegue la
 * tablet» serian el mismo dato.
 *
 * **`pending` no se almacena**: es la ausencia de fila en `setup_progress`. Dos
 * formas de decir lo mismo en una tabla acaban siempre en una consulta que trata
 * una y olvida la otra.
 */
enum SetupStepState: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';

    /** El paso ya no bloquea el cierre del asistente. */
    public function isResolved(): bool
    {
        return $this !== self::PENDING;
    }
}
