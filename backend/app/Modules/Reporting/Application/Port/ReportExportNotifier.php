<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;

/**
 * Avisa a quien lo pidio de que el informe esta listo (**RF-IN-06**, decision 8
 * de la ficha 3.9).
 *
 * ## El panel siempre; el correo si hay salida
 *
 * La pantalla de informes sondea la lista mientras haya una exportacion en curso
 * y enseña «lista para descargar»: **ese** es el canal de serie y no depende de
 * nada. El correo es un extra, y solo se intenta si la instalacion tiene un
 * transporte real (`MAIL_MAILER` distinto de `log` y de `array`) y la cuenta
 * tiene direccion.
 *
 * Eso no contradice la regla dura 12 —el producto no depende del correo del
 * **empleado**— ni la ADR-015: quien recibe esto es una cuenta de gestion, cuyo
 * `users.email` es obligatorio y unico. Y aun asi el aviso no es obligatorio:
 * doc 02 §11.6.2 admite instalaciones sin salida a internet, donde lo unico que
 * se pierde es el correo.
 *
 * ## Devuelve por donde se aviso, no si tuvo exito
 *
 * `mail` cuando el correo salio; `panel` cuando no se intento o cuando fallo. Un
 * fallo de SMTP se registra como `warning` sin datos y **no marca la exportacion
 * como fallida**: un informe generado sigue generado, y quien lo pidio lo ve en
 * la pantalla igual.
 *
 * ## El correo NUNCA lleva el enlace de descarga
 *
 * Lleva el enlace **a la pantalla** y la fecha de caducidad del fichero. El
 * enlace de descarga es de un solo uso (ADR-041): uno quemado en un buzon
 * compartido —o consumido por un antivirus de correo que sigue enlaces— seria un
 * enlace que no funciona cuando la persona lo pulsa.
 */
interface ReportExportNotifier
{
    /** Devuelve por donde se aviso: `mail` si el correo salio, `panel` en cualquier otro caso. */
    public function notify(ReportExport $export): ReportExportNotificationChannel;
}
