<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Puente entre artisan y los scripts de copia de infra/scripts.
 *
 * Deliberadamente delgado: aqui no hay ni una decision sobre que es una copia
 * valida. Eso vive en backup.sh, que es lo que se ejecuta desde cron cuando la
 * aplicacion no arranca y lo que se entrega al cliente (§11.6.1). Duplicar la
 * logica en PHP tendria el efecto clasico: dos comportamientos que divergen y
 * el que se prueba no es el que se ejecuta el dia del incidente.
 *
 * Lo unico que aporta esta clase es lo que artisan hace mejor que cron:
 * localizar el script, imponer un tiempo maximo, transmitir la salida al
 * operador segun se produce y devolver el codigo de salida sin traducirlo.
 */
final class BackupScript
{
    public function __construct(private readonly Command $command) {}

    /**
     * Ejecuta un script de infra/scripts y devuelve su codigo de salida.
     *
     * @param  list<string>  $arguments
     */
    public function run(string $script, array $arguments): int
    {
        $path = rtrim(config()->string('backup.script_path'), '/').'/'.$script;

        if (! is_file($path)) {
            $this->command->error(
                "No se encuentra '{$path}'. Comprueba BACKUP_SCRIPT_PATH: en produccion los scripts ".
                'viajan dentro de la imagen (/opt/kronoqr/scripts) y en desarrollo se leen del '.
                'repositorio montado. Ver docs/runbooks/restaurar-backup.md.'
            );

            return Command::FAILURE;
        }

        $process = new Process(
            command: ['bash', $path, ...$arguments],
            env: $this->environment(),
            timeout: (float) config()->integer('backup.timeout'),
        );

        try {
            // Salida en directo: una copia puede tardar minutos y quien la
            // lanza a mano necesita ver que avanza, no un bloque al final.
            $process->run(function (string $type, string $buffer): void {
                $this->command->getOutput()->write($buffer);
            });
        } catch (RuntimeException $e) {
            $this->command->error(
                'El script de copia no ha terminado a tiempo o no se ha podido ejecutar: '.$e->getMessage().
                ' La copia anterior sigue intacta. Ver docs/runbooks/restaurar-backup.md.'
            );

            return Command::FAILURE;
        }

        return $process->getExitCode() ?? Command::FAILURE;
    }

    /**
     * Variables que se le imponen al script.
     *
     * Solo rutas. Todo lo demas —credenciales de base de datos y, sobre todo,
     * BACKUP_ENCRYPTION_KEY— lo hereda del entorno del contenedor: pasarlo por
     * aqui lo metería en la memoria de PHP y en cualquier volcado de
     * diagnostico, sin ganar nada a cambio.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'BACKUP_PATH' => config()->string('backup.path'),
        ];
    }
}
