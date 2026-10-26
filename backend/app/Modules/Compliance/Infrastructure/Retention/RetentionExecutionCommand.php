<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Retention;

use App\Modules\Compliance\Domain\ValueObject\RetentionReport;

/**
 * La orden que ejecuta una purga simulada, tal como la imprimen la consola y
 * el informe: una sola redaccion para los dos. Lleva la
 * frase del informe y `--responsible`, que la purga exige desde la 2.3.0
 * (doc 01 RF-PR-03, §7.2 «Firmas por consola»).
 *
 * Es presentacion, no regla: por eso vive aqui y no en el dominio, que solo
 * aporta la frase (`RetentionReport::confirmationToken()`).
 */
final class RetentionExecutionCommand
{
    public static function for(RetentionReport $report): string
    {
        return 'php artisan compliance:apply-retention --confirm='.$report->confirmationToken()
            .' --responsible=<correo o uuid de una cuenta admin o rrhh>';
    }
}
