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
            $this->warn(self::text('console.backup.unverified'));
            $arguments[] = '--skip-verify';
        }

        $code = (new BackupScript($this))->run('backup.sh', $arguments);

        if ($code !== 0) {
            $this->error(self::text('console.backup.run_failed', ['code' => $code]));
        }

        return $code;
    }

    /**
     * En el idioma de `APP_LOCALE`, no en el de la instalacion: esta orden vive
     * en el armazon (`App\Console`), que no alcanza los modulos (Deptrac), y la
     * lanzan el planificador y `update.sh`, cuya salida va a su propio informe.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return \is_string($text) ? $text : $key;
    }
}
