<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;

/**
 * `php artisan backup:run` — copia de seguridad cifrada y verificada
 * (RF-PR-04, RL-12).
 *
 * Lo ejecuta el scheduler cada madrugada (routes/console.php) y una persona
 * antes de cualquier cambio arriesgado. El actualizador del producto (RF-PD-10)
 * se apoya en el: si la copia previa falla, la actualizacion no continua, y por
 * eso el codigo de salida distingue "no se ha podido hacer la copia" de "se ha
 * hecho pero no se ha podido verificar" solo en el mensaje: los dos son
 * fracaso, y los dos deben detener lo que venga detras.
 *
 * No lleva logica de dominio ni toca la base de datos: invoca backup.sh. El
 * porque esta en config/backup.php.
 */
final class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--mode=      : dump (volcado logico, por defecto), base (copia fisica) o full}
        {--skip-verify : No verifica la copia recien creada. Solo para diagnostico}';

    protected $description = 'Crea una copia de seguridad cifrada del registro y la verifica (RF-PR-04)';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $mode = is_string($mode) && $mode !== '' ? $mode : config()->string('backup.daily_mode');

        $arguments = ['run', '--mode', $mode];

        if ($this->option('skip-verify') === true) {
            $this->warn(
                'Copia SIN verificar: una copia no verificada no es una copia. '.
                'Verificala en cuanto puedas con: php artisan backup:verify'
            );
            $arguments[] = '--skip-verify';
        }

        $code = (new BackupScript($this))->run('backup.sh', $arguments);

        if ($code !== 0) {
            $this->error(
                'La copia no se ha completado (codigo '.$code.'). La copia anterior sigue siendo la buena. '.
                'Procedimiento: docs/runbooks/restaurar-backup.md'
            );
        }

        return $code;
    }
}
