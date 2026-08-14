<?php

declare(strict_types=1);

namespace App\Modules\Attendance;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Attendance — nucleo del producto: fichajes, tramos, jornadas y
 * correcciones (doc 02 §1.6). Solo puede depender de Shared.
 *
 * El nucleo declara sus puertos y no nombra a quien los sirve (ADR-025). De
 * los cinco de Attendance/Application/Port/ (tarea 1.1), aqui se enlazan solo
 * los dos que implementa el propio modulo:
 *   - WorkDayRepository -> Attendance/Infrastructure/Persistence
 *   - EventPublisher    -> Attendance/Infrastructure/Adapter
 * Los otros tres los enlazan sus satelites: CredentialResolver en
 * IdentityServiceProvider; EmployeeDirectory y SiteCalendar en
 * WorkforceServiceProvider.
 */
final class AttendanceServiceProvider extends ServiceProvider {}
