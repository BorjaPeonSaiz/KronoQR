<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\Support\WeeklySummaryReason;
use App\Modules\Reporting\Application\UseCase\SendWeeklySummaries;
use App\Modules\Reporting\Application\UseCase\WeeklySummaryPass;
use App\Modules\Reporting\Domain\Exception\InvalidIsoWeek;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan reporting:weekly-summary` — el resumen semanal por correo al
 * responsable de cada departamento (**RF-PR-05**, doc 02 Anexo C).
 *
 * Lo ejecuta el planificador los **lunes a las 06:00 UTC**
 * (`routes/console.php`), sobre la semana ISO anterior en el calendario civil
 * del centro. A mano sirve para dos cosas: comprobar que el SMTP del cliente
 * funciona —el fallo de instalacion mas frecuente de esta via— y reenviar una
 * semana concreta con `--week=2026-W38`.
 *
 * ## Repetirlo no manda nada dos veces
 *
 * Lo garantiza `weekly_summary_deliveries` con su `UNIQUE (manager_user_id,
 * week_start)`, no una comprobacion de este comando. Quien ya recibio el resumen
 * de esa semana aparece en `skipped`.
 *
 * ## El codigo de salida
 *
 * `0` en todos los desenlaces normales, **incluidos los cuatro en los que no se
 * envia nada**: el ajuste apagado, `MAIL_MAILER` en `log` o `array`, la licencia
 * sin la funcionalidad y la instalacion sin responsables de departamento. Son
 * instalaciones correctas (doc 02 §11.6.2, doc 05 §5.7 «correo opcional»), y un
 * planificador en rojo cada lunes por una de ellas entrena a no mirarlo.
 *
 * `1` solo cuando **algun envio fallo**: ahi hay un resumen que alguien
 * esperaba y no llego, y la fila de esa semana se deshizo para que se reintente.
 *
 * ## Lo que este comando NO imprime
 *
 * Ningun nombre, ninguna direccion y ninguna cifra de horas de nadie (regla dura
 * 21): la salida y el log son recuentos y un motivo. Lo que salio y a quien esta
 * en `audit_log` con el conjunto `weekly_summary`, que se queda en el servidor
 * del cliente; esta salida acaba en el log del planificador, que viaja al
 * fabricante dentro del paquete de diagnostico (ADR-020).
 */
final class WeeklySummaryCommand extends Command
{
    protected $signature = 'reporting:weekly-summary
        {--week= : Semana ISO a resumir, AAAA-Www. Por defecto, la semana pasada en la zona del centro}';

    protected $description = 'Envia el resumen semanal por correo al responsable de cada departamento (RF-PR-05)';

    public function handle(SendWeeklySummaries $summaries): int
    {
        try {
            // `--week` es una opcion con valor y sin `isArray()`, asi que
            // Symfony entrega `string` o `null`: no hay tercer caso que tratar.
            $pass = $summaries->handle($this->option('week'));
        } catch (InvalidIsoWeek $invalid) {
            // Nada que corregir en el codigo: lo escribio una persona en la
            // linea de ordenes. Se dice cual es el problema, no una traza.
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        // Recuentos y motivo, nunca personas (regla dura 21).
        Log::info('reporting.weekly_summary', [
            'reason' => $pass->reason->value,
            'week' => $pass->week,
            'recipients' => $pass->recipients,
            'sent' => $pass->sent,
            'skipped' => $pass->skipped,
            'failed' => $pass->failed(),
        ]);

        // Y una linea por envio fallido, con la cuenta y la CLASE de la
        // excepcion: el recuento de arriba dice que algo falto, y esto dice a
        // quien y de que clase era el problema, que es lo que permite separar un
        // SMTP que rechaza de un informe que agota su tiempo. Nunca el mensaje
        // de la excepcion, que puede llevar dentro el valor de una fila.
        foreach ($pass->failures as $failure) {
            Log::warning('reporting.weekly_summary_failed', [
                'manager_user_id' => $failure['manager_user_id'],
                'week' => $pass->week,
                'exception' => $failure['exception'],
            ]);
        }

        if ($pass->reason !== WeeklySummaryReason::Sent) {
            $this->line($this->explain($pass->reason));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Semana %s: %d resumen(es) enviados, %d omitidos de %d responsable(s).',
            $pass->week,
            $pass->sent,
            $pass->skipped,
            $pass->recipients,
        ));

        return $this->report($pass);
    }

    /**
     * Por que no se envio nada, en una linea que dice **donde se arregla**.
     *
     * «¿Por que no me llega el resumen?» tiene cuatro respuestas y las cuatro se
     * tocan en sitios distintos. Un «no se envio nada» a secas obligaria a
     * revisarlos todos.
     */
    private function explain(WeeklySummaryReason $reason): string
    {
        return match ($reason) {
            WeeklySummaryReason::Disabled => 'El resumen semanal esta desactivado en esta instalacion. '
                .'Se enciende en el panel, en Ajustes: WEEKLY_SUMMARY_EMAIL.',
            WeeklySummaryReason::MailerSilent => 'Esta instalacion no tiene un transporte de correo real (MAIL_MAILER). '
                .'No es un fallo: el resumen es opcional y el resto del producto funciona igual.',
            WeeklySummaryReason::NotInPlan => 'La licencia de esta instalacion no incluye el resumen semanal por correo. '
                .'El registro horario y la exportacion para la Inspeccion no dependen de ella.',
            WeeklySummaryReason::NoRecipients => 'No hay ningun responsable de departamento activo y con correo al que escribir. '
                .'Se asignan en el panel, en Departamentos.',
            WeeklySummaryReason::Sent => '',
        };
    }

    /**
     * El desenlace de la pasada.
     *
     * Distinto de cero **solo** si algun envio fallo, aunque los demas salieran:
     * ahi hay una persona esperando un correo que no llego. La alerta no existe
     * a proposito (decision 8 de la ficha); lo que queda es este codigo de
     * salida, que el planificador convierte en `scheduler.command_failed`.
     */
    private function report(WeeklySummaryPass $pass): int
    {
        if (! $pass->hasFailures()) {
            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d resumen(es) no se pudieron entregar; su semana sigue pendiente y se reintentara. '
            .'Busca «reporting.weekly_summary_failed» en el log para ver a que cuentas y de que clase '
            .'fue el problema.',
            $pass->failed(),
        ));

        return self::FAILURE;
    }
}
