<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * El tiempo real es accesorio y el arranque de la aplicacion NO puede depender
 * de el (ADR-011, reglas duras 15 y 19).
 *
 * `Broadcast::channel()` en `routes/channels.php` construye el cliente de Pusher
 * en cada arranque. Si `REVERB_APP_KEY`, `REVERB_APP_SECRET` o `REVERB_APP_ID`
 * no estan declaradas, `env()` devuelve `null`, el constructor de Pusher exige
 * cadenas y la aplicacion entera muere antes de atender nada: ni `POST /scan`,
 * ni un comando de consola, ni el `package:discover` que Composer lanza al
 * instalar. Asi cayo la CI de la tarea 2.4, donde no hay `.env`.
 *
 * Por que un SUBPROCESO y no `config()->set()`: el fallo ocurre al arrancar,
 * antes de que ninguna prueba pueda tocar la configuracion, y la suite fuerza
 * `BROADCAST_CONNECTION=null` en `phpunit.xml`. La unica forma de comprobar el
 * arranque real es arrancar de verdad, con el entorno que tendria un runner o
 * una instalacion con la variable olvidada.
 *
 * `(null)` es la forma que tiene `env()` de recibir un `null` de verdad desde el
 * entorno: la variable esta declarada en el `.env` de desarrollo y una variable
 * real del proceso es la unica que gana al fichero.
 */
it('arranca aunque falten las credenciales de Reverb', function (): void {
    $proceso = new Process(
        [PHP_BINARY, 'artisan', 'about', '--only=environment', '--no-ansi'],
        cwd: base_path(),
        env: [
            // El valor por defecto de `config/broadcasting.php`: es lo que rige
            // en la CI, donde `BROADCAST_CONNECTION` no esta declarada.
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_KEY' => '(null)',
            'REVERB_APP_SECRET' => '(null)',
            'REVERB_APP_ID' => '(null)',
        ],
        timeout: 60.0,
    );
    $proceso->run();

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput().$proceso->getOutput())
        ->and($proceso->getOutput().$proceso->getErrorOutput())
        ->not->toContain('Failed to create broadcaster');
})->group('RN-15', 'RF-PA-01');
