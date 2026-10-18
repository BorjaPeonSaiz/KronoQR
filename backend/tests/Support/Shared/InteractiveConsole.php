<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Una orden de consola con una terminal que contesta a sus preguntas.
 *
 * Por el nucleo de consola y no con `$this->artisan()`: hay que afirmar sobre la
 * salida y el codigo a la vez, y la pregunta la contesta la entrada como lo
 * haria una persona (`Artisan::call` sin TTY corre siempre sin interaccion).
 */
final class InteractiveConsole
{
    /**
     * @param  array<string, string|bool>  $options
     * @return array{code: int, output: string}
     */
    public static function run(string $command, array $options, string $answer): array
    {
        $stream = fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        fwrite($stream, $answer."\n");
        rewind($stream);

        $input = new ArrayInput(['command' => $command, ...$options]);
        $input->setStream($stream);
        $input->setInteractive(true);
        $output = new BufferedOutput;

        $code = resolve(ConsoleKernel::class)->handle($input, $output);

        return ['code' => $code, 'output' => $output->fetch()];
    }
}
