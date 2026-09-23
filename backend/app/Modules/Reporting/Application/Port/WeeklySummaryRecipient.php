<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Un responsable de departamento que recibe el resumen semanal (**RF-PR-05**,
 * decision 2 de la ficha 3.12).
 *
 * ## Trae su alcance, y esa es la mitad del requisito
 *
 * `scope` es el **mismo** `AccessScope` que aplica el panel a esa cuenta
 * (RF-ID-03), y entra en el `WHERE` del informe: el de Cocina no ve a nadie de
 * Recepcion. Viaja dentro del destinatario —y no al lado— por lo mismo que en
 * {@see PeriodReportQuery}: para que
 * no exista ninguna ruta por la que se pueda componer el resumen de alguien sin
 * el suyo.
 *
 * ## Ni `rrhh` ni `admin` estan aqui, y es deliberado
 *
 * Tienen el panel entero y un correo semanal con toda la plantilla seria una
 * copia periodica del registro **fuera** del sistema, reenviable y sin control
 * de acceso: minimizacion (RGPD art. 5.1.c). Quien quiera el cuadro completo lo
 * abre en el panel, donde el acceso queda auditado.
 *
 * ## La direccion es obligatoria aqui, al contrario que en el aviso de informes
 *
 * {@see ReportExportRecipient} la admite nula porque alli el correo es el canal
 * secundario y la pantalla es el de serie. Aqui el correo **es** la
 * funcionalidad: una cuenta sin direccion no es un destinatario, y el adaptador
 * la deja fuera de la lista en vez de entregar un destinatario al que no se
 * puede escribir.
 */
final readonly class WeeklySummaryRecipient
{
    public function __construct(
        /** `users.id`. Es lo que va al asiento de auditoria y a la tabla de envios; nunca el nombre. */
        public int $userId,
        public string $email,
        /** `users.locale`: el correo va en el idioma de la cuenta, como el resto. */
        public string $locale,
        public AccessScope $scope,
    ) {}
}
