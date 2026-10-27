<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\UseCase\PruneSupportGrantReasons;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan product:support-grants:prune-reasons` — vacia el motivo de las
 * concesiones de soporte cerradas hace mas de
 * `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS` (RGPD art. 5.1.e).
 *
 * Hermana de `product:errors:prune`: diaria, automatica y sin asiento. La
 * concesion se sigue listando entera; el motivo original sigue en el asiento
 * `support_grant.granted`.
 *
 * La salida son recuentos y el plazo, nunca un motivo: acaba en el log del
 * planificador, que viaja en el paquete de diagnostico (regla dura 21).
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Hecho, o nada que vaciar. |
 * | `1` | No se pudo completar. No se ha vaciado nada a medias: es una sola sentencia. |
 */
final class SupportGrantsPruneReasonsCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Clave de `lang/*\/support.php` con los textos de esta orden. */
    private const string TEXTS = 'support.prune';

    protected $signature = 'product:support-grants:prune-reasons
        {--dry-run : Cuenta lo que se vaciaria y no vacia nada}';

    protected $description = 'Vacia el motivo de las concesiones de soporte cerradas hace mas de PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS';

    public function handle(PruneSupportGrantReasons $prune): int
    {
        try {
            if ($this->option('dry-run') === true) {
                $this->line($this->text('dry_run', ['rows' => $prune->pending(), 'days' => $prune->retentionDays()]));

                return self::SUCCESS;
            }

            $this->line($this->text('done', ['rows' => $prune->handle(), 'days' => $prune->retentionDays()]));

            return self::SUCCESS;
        } catch (Throwable $failure) {
            // La CLASE y nunca el mensaje: un `QueryException` lleva la
            // sentencia, y la sentencia no lleva nada que el log necesite.
            $this->line($this->text('failed', ['failure' => $failure::class]));

            return self::FAILURE;
        }
    }
}
