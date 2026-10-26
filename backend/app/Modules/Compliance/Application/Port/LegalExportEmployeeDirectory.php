<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;

/**
 * Busca a la persona que nombra un requerimiento de la Inspeccion (RF-IN-05).
 *
 * ## Por que un puerto de `Compliance` y no el directorio de `Workforce`
 *
 * El `auditor` no tiene el directorio de plantilla (`employees:read`, RF-ID-02)
 * y esto no debe parecerse a el: busca con un criterio obligatorio, devuelve
 * pocas personas y con los campos minimos. Reutilizar `GET /employees` habria
 * sido darle la plantilla entera, y `Compliance` no puede importar de
 * `Workforce` (doc 02 §1.6). El adaptador lee la tabla `employees` como ya hace
 * la propia exportacion legal.
 *
 * ## Lo que promete el adaptador
 *
 * - Incluye a las personas **dadas de baja y suspendidas**: el registro se
 *   conserva cuatro años (RL-02) y la Inspeccion pregunta tambien por quien ya
 *   se fue.
 * - Orden estable por apellidos, nombre y `uuid`, para que el recorte a
 *   `$limit` sea siempre el mismo con la misma plantilla.
 * - El termino y el documento van **siempre como parametro enlazado**, nunca
 *   interpolados en el texto de la consulta.
 */
interface LegalExportEmployeeDirectory
{
    /**
     * Como mucho `$limit` personas que casan con la busqueda.
     *
     * @param  positive-int  $limit
     * @return list<LegalExportEmployeeMatch>
     */
    public function search(EmployeeSearch $search, int $limit): array;
}
