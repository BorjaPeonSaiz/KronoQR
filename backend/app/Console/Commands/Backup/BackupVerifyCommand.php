<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;

/**
 * `php artisan backup:verify` — comprueba que la ultima copia se puede
 * restaurar de verdad (RF-PR-04).
 *
 * Verificar no es mirar si el fichero existe: el script comprueba la huella
 * SHA-256 del fichero cifrado, lo descifra entero con la clave de la
 * instalacion y hace que pg_restore lea su indice. Si cualquiera de las tres
 * falla, la copia no vale y el codigo de salida lo dice.
 *
 * La prueba completa —restaurar en un contenedor limpio y comparar integridad
 * referencial y conteos— es el simulacro trimestral
 * (infra/scripts/restore-drill.sh, RNF-D-05). Esta verificacion es la barata,
 * la que se ejecuta todos los dias detras de cada copia.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify
        {--file= : Ruta de una copia concreta. Por defecto, la ultima}';

    protected $description = 'Verifica que la ultima copia esta integra y se puede descifrar y leer (RF-PR-04)';

    public function handle(): int
    {
        $arguments = ['verify'];

        $file = $this->option('file');
        if (is_string($file) && $file !== '') {
            $arguments[] = '--file';
            $arguments[] = $file;
        }

        $code = (new BackupScript($this))->run('backup.sh', $arguments);

        if ($code !== 0) {
            $this->error(
                'La copia NO esta verificada (codigo '.$code.'). Tratala como inexistente hasta resolverlo: '.
                'docs/runbooks/restaurar-backup.md'
            );
        }

        return $code;
    }
}
