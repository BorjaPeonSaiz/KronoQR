<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El asiento de la reconciliacion tiene que bastar para reconstruir la fila
 * mala** (RL-04, RN-06, RF-PR-02).
 *
 * Desde la tarea 3.6 la correccion **no escribe nada** cuando la sospecha se
 * deshace al releer, de modo que cuando escribe es porque la divergencia era
 * real — y este asiento pasa a ser la unica copia de lo que la proyeccion
 * afirmaba, porque la fila ya se reescribio y `audit_log` es solo-append
 * (ADR-027, regla dura 6). Con dos de los seis campos comparados no se puede
 * decir que decia una fila que afirmaba «turno abierto» sobre un turno cerrado,
 * que es justo la divergencia que destapo la prueba de carga.
 *
 * Se ejecuta el **comando** y no el caso de uso: el asiento lo escribe un
 * listener que registra `ComplianceServiceProvider::boot()`, y lo que se quiere
 * comprobar es la cadena entera tal como corre de madrugada.
 *
 * Aqui se cubre el caso de la **fila ausente**, que es el que no tiene «antes»:
 * `before` va a `null` y no a un mapa de ceros, porque «la proyeccion decia un
 * dia a cero» y «la proyeccion no decia nada» son dos hechos distintos y solo
 * uno de los dos es el que ocurrio. El caso de la fila escrita con otros valores
 * —los seis campos a cada lado— lo cubre `DailyTotalsReconciliationTest`.
 */

uses(RefreshDatabase::class);

it('deja en el asiento los seis campos y un antes vacio cuando faltaba la fila', function (): void {
    FrozenTime::at('2026-03-15 03:50:00');

    $site = WorkforceFixtures::site('Hotel del asiento', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Cocina'));

    // Una jornada con su tramo y **sin fila en la proyeccion**: la peor de las
    // divergencias, porque ninguna consulta guiada por `daily_totals` la
    // encontraria nunca. Se escribe por SQL porque por el camino bueno la fila
    // se habria recalculado sola.
    DB::table('shift_entries')->insert([
        'uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        'employee_id' => AttendanceFixtures::employeeIdOf($employee),
        'site_id' => $site,
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 06:00:00+00',
        'clocked_out_at' => '2026-03-14 14:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-03-14 06:00:00+00',
        'updated_at' => '2026-03-14 14:00:00+00',
    ]);

    [$exitCode, $output] = Commands::run('attendance:reconcile --from=2026-03-14 --to=2026-03-14');

    // Distinto de cero aunque lo haya corregido: una divergencia no es
    // mantenimiento rutinario.
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('fila ausente: 1');

    $entry = DB::table('audit_log')->where('subject_type', 'daily_totals')->first();

    $payload = Row::of($entry ?? throw new RuntimeException('La correccion no dejo asiento en audit_log.'))
        ->json('payload') ?? [];

    expect($payload['employee_uuid'] ?? null)->toBe($employee)
        ->and($payload['work_date'] ?? null)->toBe('2026-03-14')
        ->and($payload['row_was_missing'] ?? null)->toBeTrue()
        ->and($payload['divergent_fields'] ?? null)->toBe(['row'])
        // Sin «antes» porque no habia fila. Un mapa de nulos aqui se leeria como
        // «la fila existia y estaba vacia», que es otra cosa. La clave **esta** y
        // vale `null`: se comprueba con `array_key_exists` porque un `??` daria
        // por bueno tambien que el asiento no la llevara.
        ->and(array_key_exists('before', $payload))->toBeTrue()
        ->and($payload['before'])->toBeNull()
        // Y el «despues» con los seis campos, instantes incluidos: es lo que
        // permite contrastar el asiento con los tramos que lo originaron.
        ->and($payload['after'] ?? null)->toEqualCanonicalizing([
            'total_minutes' => 480,
            'shift_count' => 1,
            'first_in_at' => '2026-03-14T06:00:00.000000+00:00',
            'last_out_at' => '2026-03-14T14:00:00.000000+00:00',
            'has_open_shift' => false,
            'has_incident' => false,
        ]);

    // Regla dura 21: el asiento identifica por UUID y no lleva ningun nombre.
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Hotel del asiento');
})->group('RL-04', 'RN-06');
