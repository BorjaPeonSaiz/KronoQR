<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use SensitiveParameter;

/**
 * Lo comun de los comandos `identity:*` del ciclo de vida de una cuenta de
 * gestion (RF-ID-10): leer opciones con su tipo y enseñar una contrasena
 * temporal **una sola vez**.
 *
 * Los ayudantes de consola de Laravel devuelven `mixed`; con PHPStan 9 eso
 * obliga a estrechar el tipo en algun sitio, y mejor aqui, una vez.
 */
abstract class AbstractManagementAccountCommand extends Command
{
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return \is_string($value) ? trim($value) : '';
    }

    protected function asked(string $question): string
    {
        $answer = $this->ask($question);

        return \is_string($answer) ? trim($answer) : '';
    }

    /**
     * Un motivo por omision y no una cadena vacia: el asiento tiene que decir
     * algo. «Sin motivo declarado» es informacion; el vacio no.
     */
    protected function reason(): string
    {
        return $this->stringOption('reason') ?? 'Sin motivo declarado';
    }

    /**
     * La UNICA vez que esta contrasena se puede leer: lo que se guarda es su
     * hash. Va sola en su linea para que se pueda copiar sin arrastrar nada mas.
     */
    protected function showTemporaryPassword(#[SensitiveParameter] string $password, DateTimeImmutable $expiresAt): void
    {
        $this->newLine();
        $this->line('  '.$password);
        $this->newLine();

        $this->components->warn(
            'Es una contrasena TEMPORAL: caduca el '.$expiresAt->format('Y-m-d H:i').' UTC y su titular tendra que '
            .'cambiarla al entrar. Anotala ahora: no se puede volver a consultar. Se entrega en mano, nunca por '
            .'correo ni por mensajeria.'
        );
    }
}
