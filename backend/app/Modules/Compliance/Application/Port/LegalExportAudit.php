<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;

/**
 * Deja constancia de que alguien genero una exportacion legal (regla dura 6,
 * `/revision-cumplimiento` bloque D, RS-05).
 *
 * ## Por que un puerto y no una llamada directa a `RecordAuditEntry`
 *
 * El caso de uso podria invocar `RecordAuditEntry`, que vive en este mismo
 * modulo. No lo hace por una razon concreta: el **actor** no esta en la orden,
 * esta en la peticion en curso, y averiguarlo exige leer el guard de
 * autenticacion. Eso es infraestructura, y meterlo en `Application` convertiria
 * el caso de uso en algo que no se puede ejecutar sin sesion — justo lo que hace
 * falta para el comando de consola de un requerimiento a las siete de la mañana.
 *
 * Es la misma forma que {@see PersonalDataAccessLog}
 * y por el mismo motivo: **quien accede no declara quien es**.
 *
 * ## Deliberadamente estrecho
 *
 * No acepta accion ni actor. La accion es siempre
 * `AuditAction::LegalExportGenerated` y la decide el adaptador; si aceptara una
 * cualquiera, el catalogo cerrado de acciones dejaria de estar cerrado.
 *
 * ## Que se apunta y que no
 *
 * Periodo, alcance y **cuantas** filas y personas (RS-05: convierte «alguien
 * miro» en «alguien se llevo la plantilla entera»). Nunca la lista de personas
 * exportadas ni el contenido del fichero: `audit_log` se conserva cuatro años y
 * no puede acabar siendo una segunda copia del registro horario (regla dura 21).
 */
interface LegalExportAudit
{
    public function recordGeneration(LegalExportManifest $manifest, LegalExportTally $tally): void;
}
