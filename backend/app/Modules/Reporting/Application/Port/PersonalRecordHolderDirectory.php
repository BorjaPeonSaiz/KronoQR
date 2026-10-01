<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * El **nombre de la persona** cuyo registro propio se imprime (PR19, RF-ID-05).
 *
 * ## Por que un nombre, si la regla dura 21 los prohibe
 *
 * La regla dura 21 prohibe nombres en **logs tecnicos** y en `error_events`,
 * porque aquello viaja al fabricante. Esto es lo contrario: el PDF de su propio
 * registro horario, que la persona descarga para presentarlo ante un tercero.
 * Un registro de jornada que no dice de quien es no sirve de nada en papel.
 * El titulo del documento y el nombre del fichero siguen sin llevarlo.
 *
 * Es la contraparte de {@see ReportIssuerDirectory} para el portal: aquel
 * resuelve la **cuenta de gestion** que emite un informe; este, la persona de
 * la plantilla dueña del registro.
 *
 * ## Devuelve `null` y el documento sigue saliendo
 *
 * Igual que aquel: una ficha que no se pudiera leer no deja a nadie sin su
 * registro. El documento imprime el rotulo de persona no identificable.
 */
interface PersonalRecordHolderDirectory
{
    /**
     * @param  string  $employeeUuid  `employees.uuid` del titular de la sesion de portal.
     * @return string|null Nombre y apellidos, o `null` si no se puede resolver.
     */
    public function fullNameOf(string $employeeUuid): ?string;
}
