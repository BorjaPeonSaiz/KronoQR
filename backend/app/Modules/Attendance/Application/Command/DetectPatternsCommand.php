<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use InvalidArgumentException;

/**
 * La orden de buscar patrones anomalos de uso de credencial (RF-PR-06, RN-16).
 *
 * **Un solo parametro, y es la ventana.** Treinta dias de serie y no los siete
 * de `attendance:detect-incidents`: «sistematico» (RF-PR-06, doc 05 §12) no se
 * puede afirmar sobre una semana, y el Gherkin del doc 01 §11 ya habla de cinco
 * dias de repeticion. El valor sale de `config/compliance.php`
 * (`COMPLIANCE_PATTERN_LOOKBACK_DAYS`), que es una variable de operacion y no un
 * ajuste del panel: no dice cuando algo es anomalo —eso lo dicen los tres
 * umbrales de `installation_settings`— sino hasta donde mira el proceso.
 *
 * **La ventana no reprocesa el historico.** Ampliarla para una ejecucion
 * concreta es `--days`, una decision consciente de quien lanza el comando.
 */
final readonly class DetectPatternsCommand
{
    public function __construct(public int $lookbackDays)
    {
        if ($lookbackDays < 1) {
            throw new InvalidArgumentException(
                'La ventana de deteccion de patrones es de al menos un dia, y ha llegado '.$lookbackDays.'. '
                .'Con menos no hay nada que comparar: un patron se afirma sobre varios dias.'
            );
        }
    }
}
