<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Assert;

/**
 * Lo que hace falta para observar el rastro de autenticacion desde una prueba:
 * el contador sustituido por un doble, los apuntes del log recogidos y los
 * asientos de `audit_log` ya decodificados.
 *
 * Vive en una clase y no en funciones sueltas de un fichero de Pest porque las
 * tres pruebas que lo necesitan —panel, portal y PIN del quiosco— estan en tres
 * ficheros distintos, y las funciones de Pest son globales: la segunda
 * definicion seria un error fatal.
 */
final class AuthenticationTrail
{
    /**
     * Sustituye el contador por un doble que recuerda lo medido.
     */
    public static function countingMetrics(): RecordingAuthenticationMetrics
    {
        $metrics = new RecordingAuthenticationMetrics;

        app()->instance(AuthenticationMetrics::class, $metrics);

        return $metrics;
    }

    /**
     * Recoge los apuntes `auth.*` del log tecnico que se escriban a partir de
     * ahora.
     *
     * @param  list<array{message: string, context: array<string, mixed>}>  $entries
     */
    public static function captureLog(array &$entries): void
    {
        Log::listen(function (MessageLogged $event) use (&$entries): void {
            if (! str_starts_with($event->message, 'auth.')) {
                return;
            }

            $entries[] = ['message' => $event->message, 'context' => $event->context];
        });
    }

    /**
     * Los asientos de `audit_log`, en orden de escritura y ya decodificados.
     *
     * @return list<array{action: string, actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, ip: string|null, payload: array<string, mixed>, raw: string}>
     */
    public static function auditEntries(): array
    {
        $entries = [];

        /** @var object{action: string, actor_type: string, actor_id: int|string|null, subject_type: string|null, subject_id: int|string|null, ip: string|null, payload: string} $row */
        foreach (DB::table('audit_log')->orderBy('id')->get() as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

            $entries[] = [
                'action' => $row->action,
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
                'ip' => $row->ip,
                'payload' => $payload,
                'raw' => $row->payload,
            ];
        }

        return $entries;
    }

    /**
     * El unico asiento que debe existir. Falla —en vez de devolver el primero—
     * si hay mas de uno: un segundo asiento inesperado es exactamente el defecto
     * que estas pruebas vigilan.
     *
     * @return array{action: string, actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, ip: string|null, payload: array<string, mixed>, raw: string}
     */
    public static function onlyAuditEntry(): array
    {
        $entries = self::auditEntries();

        Assert::assertCount(1, $entries, 'Se esperaba exactamente un asiento en audit_log.');

        return $entries[0];
    }
}
