<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El paso de la actualizacion en el que algo fallo (RF-PD-10, tarea 5.7).
 *
 * **Vocabulario cerrado y no el numero de paso.** `update.sh` numera sus fases
 * del 1 al 7 y ese numero puede cambiar el dia que se anada o se junte una: un
 * `failed_step: 4` escrito hoy significaria otra cosa dentro de dos versiones, y
 * el trail se conserva cuatro años. El nombre no se mueve.
 *
 * **Y no texto libre**, por lo mismo que {@see SystemRestoreReason}: quien lee
 * el trail dos años despues necesita agrupar «¿cuantas vueltas atras hubo por
 * migracion?», y eso no se hace con la frase que un script tradujo al idioma del
 * operador.
 *
 * Correspondencia con los pasos de `update.sh` (§ del script entre parentesis):
 *
 * | `STEP` | Fase del script                          | Valor            |
 * |--------|------------------------------------------|------------------|
 * | 1      | comprobaciones previas                   | `preflight`      |
 * | 2      | modo mantenimiento y parada de procesos  | `maintenance`    |
 * | 3      | copia verificada                         | `backup`         |
 * | 4      | migraciones version a version            | `migrations`     |
 * | 5      | arranque y verificacion sin exponer      | `start_and_verify` |
 * | 5 (2.ª mitad) | apertura del borde y salida de mantenimiento | `expose` |
 *
 * El paso 6 es la vuelta atras y el 7 el informe: ninguno de los dos puede ser
 * el paso que **provoco** la vuelta atras, y por eso no tienen valor.
 */
enum SystemUpdateStep: string
{
    case Preflight = 'preflight';

    case Maintenance = 'maintenance';

    case Backup = 'backup';

    case Migrations = 'migrations';

    case StartAndVerify = 'start_and_verify';

    case Expose = 'expose';

    /**
     * No se pudo determinar: la vuelta atras la disparo el `trap` de error o una
     * senal (`INT`, `TERM`, `HUP`).
     *
     * **Existe a proposito.** Sin este valor, el script tendria que elegir un
     * paso plausible cuando no lo sabe, y un asiento que adivina es peor que uno
     * que dice que no sabe.
     */
    case Unknown = 'unknown';
}
