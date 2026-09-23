<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

/**
 * **Por que la pasada semanal termino como termino** (RF-PR-05, decision 4 de la
 * ficha 3.12).
 *
 * ## Los cuatro primeros no son errores
 *
 * Una instalacion sin SMTP, sin la funcionalidad contratada, con el ajuste
 * apagado o sin ningun responsable de departamento es una instalacion **normal**
 * (doc 02 §11.6.2: hay clientes sin salida a internet). La pasada sale con
 * exito, no envia nada y lo dice. Convertir cualquiera de los cuatro en un fallo
 * llenaria de rojo el planificador de un cliente que no ha hecho nada mal, y el
 * dia que fallara algo de verdad nadie lo miraria.
 *
 * ## Y por eso se escriben en el log
 *
 * «¿Por que no me llega el resumen?» tiene cuatro respuestas distintas y las
 * cuatro se arreglan en sitios distintos: el panel, la licencia, el `.env` y la
 * ficha de los departamentos. Sin el motivo en `reporting.weekly_summary`, la
 * unica pista seria un recuento de cero, que no distingue ninguna de las cuatro.
 *
 * Los valores van en ingles y en minusculas: acaban en un log tecnico que viaja
 * al fabricante dentro del paquete de diagnostico (ADR-020). Nunca llevan
 * nombres ni direcciones (regla dura 21).
 */
enum WeeklySummaryReason: string
{
    /** `WEEKLY_SUMMARY_EMAIL` esta en `disabled`, que es el valor de serie. */
    case Disabled = 'disabled';

    /**
     * `MAIL_MAILER` es `log` o `array`: «enviar» seria escribir en un fichero.
     *
     * Mismo criterio que `MailReportExportNotifier`, nombrado en prosa y no con
     * una referencia: Pint la resolveria a un `use` de `Infrastructure` desde
     * `Application`, que es una arista que Deptrac rechaza.
     */
    case MailerSilent = 'mailer_silent';

    /** La licencia no incluye `weekly_email_summary` (ADR-019, regla dura 15). */
    case NotInPlan = 'not_in_plan';

    /** No hay ningun responsable de departamento activo y con direccion. */
    case NoRecipients = 'no_recipients';

    /** La pasada llego a enviar. Puede haber envios fallidos y omitidos igualmente. */
    case Sent = 'sent';
}
