<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Identity — usuarios, roles, permisos, credenciales QR y tokens de
 * dispositivo (doc 02 §1.6). Depende de Shared y de
 * Attendance/Application/Port, cuyo puerto implementa.
 *
 * Enlace pendiente (tarea 1.5, ADR-025):
 *   - Attendance\Application\Port\CredentialResolver -> HmacSignatureVerifier
 * El adaptador vive en Identity/Infrastructure/Adapter/, que es donde esta la
 * tabla credentials. Attendance no sabe quien le resuelve la credencial.
 */
final class IdentityServiceProvider extends ServiceProvider {}
