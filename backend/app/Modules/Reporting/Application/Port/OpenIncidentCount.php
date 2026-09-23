<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Cuantas incidencias sin resolver tiene un alcance (RF-PR-05, decision 3 de la
 * ficha 3.12).
 *
 * **Un numero y nada mas.** El detalle —quien, que dia y de que tipo— ya sale en
 * el aviso diario de RF-PR-01, y repetirlo en el resumen semanal seria un
 * segundo correo con los mismos nombres. Lo que este recuento aporta es el
 * contexto que a aquel le falta: «tienes cuatro sin resolver», que es lo que
 * impide que una bandeja que nadie mira crezca en silencio.
 *
 * **Por el alcance y no por el destinatario** (RF-ID-03): se cuentan las
 * incidencias de las personas que ese responsable alcanza, exactamente las
 * mismas que ve en su bandeja. Contar por `assigned_to_user_id` daria otro
 * numero —el de las que le han asignado— y el correo diria algo distinto de lo
 * que la pantalla enseña.
 *
 * El adaptador vive en `Reporting/Infrastructure`, que es donde `Compliance` ya
 * deja leer su tabla a este modulo (`DatabaseComplianceIncidentLinks`, tarea
 * 3.4): `Reporting` no puede importar `Compliance` (doc 02 §1.6) y la lectura
 * acotada de `incidents` por SQL es la licencia que ADR-025 concede.
 */
interface OpenIncidentCount
{
    public function inScope(AccessScope $scope): int;
}
