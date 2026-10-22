<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

/**
 * **Por que la pasada semanal termino como termino** (RF-PR-05, decision 4 de la
 * ficha 3.12).
 *
 * ## Los motivos sin envio no son errores
 *
 * Una instalacion sin SMTP, sin la funcionalidad en su plan, con la licencia
 * sin renovar, con el ajuste apagado o sin ningun responsable de departamento
 * es una instalacion **normal** (doc 02 §11.6.2: hay clientes sin salida a
 * internet). La pasada sale con exito, no envia nada y lo dice. Convertir
 * cualquiera de ellos en un fallo llenaria de rojo el planificador de un
 * cliente que no ha hecho nada mal, y el dia que fallara algo de verdad nadie
 * lo miraria.
 *
 * ## Y por eso se escriben en el log
 *
 * «¿Por que no me llega el resumen?» tiene cinco respuestas distintas, y las
 * cinco se arreglan en sitios distintos: el panel, el plan, la renovacion, el
 * `.env` y la ficha de los departamentos. Sin el motivo en
 * `reporting.weekly_summary`, la unica pista seria un recuento de cero, que no
 * distingue ninguna de las cinco.
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

    /**
     * La licencia esta en vigor y su plan no incluye `weekly_email_summary`
     * (ADR-019, regla dura 15). Se arregla ampliando el plan.
     */
    case NotInPlan = 'not_in_plan';

    /**
     * La licencia no esta en vigor —caducada, sin activar, todavia no vigente
     * o sin verificar— (V4-PL-4, ADR-023). Se arregla renovandola o
     * activandola, y el resumen vuelve solo: decir «el plan no lo incluye» aqui
     * mandaba a cambiar de plan a quien solo tenia que renovar.
     */
    case LicenseInactive = 'license_inactive';

    /** No hay ningun responsable de departamento activo y con direccion. */
    case NoRecipients = 'no_recipients';

    /** La pasada llego a enviar. Puede haber envios fallidos y omitidos igualmente. */
    case Sent = 'sent';
}
