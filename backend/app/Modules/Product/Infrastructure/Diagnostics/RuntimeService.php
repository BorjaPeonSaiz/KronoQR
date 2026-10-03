<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

/**
 * El contenedor en el que corre `product:doctor` (`backup.runtime_service`,
 * variable `KRONOQR_SERVICE`).
 *
 * Desde la 2.2.0 (bloque 20, A3-R2) cada servicio monta `BACKUP_PATH` a su
 * manera, y lo que una sonda debe encontrar escribible depende de donde se
 * ejecuta:
 *
 * | Servicio    | raiz | `metrics/` | `reports/retention` | `daily/` `base/` |
 * |-------------|------|------------|---------------------|------------------|
 * | `app`       | ro   | rw         | rw                  | ro               |
 * | `horizon`   | ro   | rw         | ro                  | ro               |
 * | `scheduler` | ro   | rw         | rw                  | rw               |
 *
 * Un valor desconocido o vacio cuenta como `app`: es donde lo lanzan
 * `install.sh`, `update.sh` y `doctor.sh`, y un diagnostico que se negara a
 * ejecutarse por una variable mal escrita seria inutil justo cuando hace falta.
 */
enum RuntimeService: string
{
    case App = 'app';
    case Horizon = 'horizon';
    case Scheduler = 'scheduler';

    public static function fromConfig(mixed $value): self
    {
        return self::tryFrom(strtolower(trim(\is_string($value) ? $value : ''))) ?? self::App;
    }

    /** La propuesta semanal (`scheduler`) y la purga real (`run --rm app`). Nunca `horizon`. */
    public function writesRetentionReports(): bool
    {
        return $this !== self::Horizon;
    }

    /** Las copias (`daily/`, `base/`) solo las escribe el planificador, que es quien las hace. */
    public function writesBackups(): bool
    {
        return $this === self::Scheduler;
    }
}
