<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;

/**
 * La cota de continuacion de RN-05 con su umbral escrito donde se lee.
 *
 * `standard()` es el descanso minimo entre jornadas del perfil `ES-hosteleria`:
 * 12 h. Es configuracion por centro (regla dura 14), asi que la prueba que va a
 * por el limite lo escribe con {@see ofHours()}.
 */
final class ContinuationCeilingFactory
{
    /** 12 h, el descanso minimo de `ES-hosteleria`. */
    public static function standard(): ContinuationCeiling
    {
        return self::ofHours(12);
    }

    public static function ofHours(int $hours): ContinuationCeiling
    {
        return ContinuationCeiling::belowRestOf(WorkedDuration::ofHours($hours));
    }
}
