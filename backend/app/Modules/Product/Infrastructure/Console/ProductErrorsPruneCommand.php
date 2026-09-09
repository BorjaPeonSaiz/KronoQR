<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\PruneErrorEvents;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * `php artisan product:errors:prune` — la purga del historico tecnico (Anexo C
 * del doc 01, **RF-PD-15**, RL-11, decision 10 de la ficha 5.12).
 *
 * ## Esto NO es la purga del registro legal, y la diferencia se nota en todo
 *
 * `compliance:apply-retention` purga jornadas y asientos a los cuatro anos del
 * perfil de cumplimiento: pide un token de confirmacion, deja informe, escribe
 * en `audit_log` y suelta particiones con el rol de mantenimiento. Este comando
 * borra errores tecnicos a los 90 dias (`ERROR_HISTORY_RETENTION_DAYS`), corre
 * solo cada madrugada y no confirma nada con nadie.
 *
 * Pedir un token para borrar un error de hace tres meses convertiria la purga en
 * algo que nadie ejecuta, y una tabla de diagnostico que crece sin fin acaba
 * siendo un problema de disco en el servidor del cliente — al que no podemos
 * entrar (ADR-016).
 *
 * ## `--dry-run` cuenta con el mismo corte que borra
 *
 * Un ensayo que dijera una cifra y una ejecucion que se llevara otra seria peor
 * que no tener ensayo.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Hecho, o nada que hacer. |
 * | `1` | No se pudo purgar. La cifra de la incidencia esta en el mensaje. |
 *
 * `0` tambien cuando no habia nada que borrar, que es el caso normal: una tarea
 * programada que saliera distinto de cero por no tener trabajo llenaria el
 * monitor del cliente de falsas alarmas cada madrugada.
 */
final class ProductErrorsPruneCommand extends Command
{
    protected $signature = 'product:errors:prune
        {--dry-run : Cuenta lo que se borraria y no borra nada}
        {--lang= : Idioma del mensaje (es|en). Por defecto, el de la instalacion}';

    protected $description = 'Borra del historico de errores los grupos mas antiguos que ERROR_HISTORY_RETENTION_DAYS';

    public function handle(
        PruneErrorEvents $prune,
        DoctorTranslator $translator,
        GetSettingsHandler $settings,
        Config $config,
    ): int {
        $locale = $this->resolveLocale($settings, $config);

        $say = fn (string $key, array $params = []): string => $translator->translate(
            'errors.prune.'.$key,
            $params,
            $locale,
        ) ?? 'errors.prune.'.$key;

        try {
            if ($this->option('dry-run') === true) {
                $this->line($say('dry_run', [
                    'rows' => $prune->pending(),
                    'days' => $prune->retentionDays(),
                ]));

                return self::SUCCESS;
            }

            $this->line($say('done', [
                'rows' => $prune->handle(),
                'days' => $prune->retentionDays(),
            ]));

            return self::SUCCESS;
        } catch (Throwable $failure) {
            /*
             * La CLASE del fallo y nunca su mensaje: un error de PostgreSQL
             * puede llevar dentro el valor de una fila (regla dura 21), y esta
             * salida acaba en el log del planificador, que viaja en el paquete
             * de diagnostico.
             */
            $this->line($say('failed', ['failure' => $failure::class]));

            return self::FAILURE;
        }
    }

    /**
     * Mismo orden que `product:doctor`: `--lang`, el idioma de la instalacion y
     * `APP_LOCALE`. Tolerante, porque esto corre en el planificador y no puede
     * dejar de purgar porque la configuracion no se pueda leer.
     */
    private function resolveLocale(GetSettingsHandler $settings, Config $config): string
    {
        $requested = $this->option('lang');

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        try {
            $configured = $settings->handle()->text(SettingKey::LOCALE_DEFAULT);

            if ($configured !== '') {
                return $configured;
            }
        } catch (Throwable) {
            // Ver `product:doctor`: sin configuracion legible se sigue con el
            // idioma del `.env`.
        }

        $fallback = $config->get('app.locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'es';
    }
}
