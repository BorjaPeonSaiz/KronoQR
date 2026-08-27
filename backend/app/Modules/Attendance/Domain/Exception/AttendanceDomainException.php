<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use DomainException;

/**
 * Raiz de las excepciones del nucleo de fichaje.
 *
 * Existe para que el caso de uso de la tarea 1.4 pueda distinguir «el dominio
 * ha rechazado esto» de «algo se ha roto», que son dos respuestas distintas: lo
 * primero se convierte en incidencia para revision humana y **nunca bloquea al
 * empleado** (regla dura 19, RF-AT-10, RN-15); lo segundo es un error.
 *
 * Los mensajes van en ingles, como el resto del codigo, y **jamas llevan el
 * nombre del empleado** (regla dura 21): se identifica por `employee_uuid`,
 * porque estos mensajes acaban en el log tecnico y en `error_events`, y el
 * historico de errores viaja al fabricante en el paquete de diagnostico.
 */
abstract class AttendanceDomainException extends DomainException {}
