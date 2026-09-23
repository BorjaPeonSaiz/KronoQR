<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Reporting\Application\Port\WeeklySummaryDeliveries;
use App\Modules\Reporting\Application\Port\WeeklySummaryMailer;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Application\Support\WeeklySummaryReason;
use App\Modules\Reporting\Application\UseCase\SendWeeklySummaries;
use App\Modules\Reporting\Application\UseCase\WeeklySummaryPass;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use App\Modules\Reporting\Infrastructure\Notification\WeeklySummaryNotification;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El resumen semanal por correo (**RF-PR-05**, tarea 3.12).
 *
 * ## Por que esto es integracion y no feature
 *
 * Porque no hay endpoint: lo dispara el planificador. Y porque lo que hay que
 * defender son hechos de la base de datos —que el alcance entra en el `WHERE`,
 * que el `UNIQUE` impide el segundo envio y que el asiento queda escrito— que
 * ninguna prueba con dobles puede afirmar.
 *
 * ## Lo que se prueba aqui, en una frase cada cosa
 *
 *   · **El alcance.** El de Cocina no recibe a nadie de Recepcion (RF-ID-03).
 *   · **Que no enviar no es un fallo.** Sin SMTP, sin licencia, con el ajuste
 *     apagado o sin destinatarios, la pasada sale bien y dice por que.
 *   · **Que el envio deja constancia** con el conjunto `weekly_summary` y la
 *     lista de afectados (RS-05, RL-15).
 *   · **Que repetirlo no reenvia** (`weekly_summary_deliveries`).
 *   · **Que el log no lleva nombres** (regla dura 21, RF-PD-15): viaja al
 *     fabricante dentro del paquete de diagnostico.
 *
 * ## La semana
 *
 * El reloj se fija el **lunes 21 de septiembre de 2026 a las 06:00 UTC**, que es
 * cuando corre la tarea. La semana pasada es entonces `2026-W38`, del lunes 14
 * al domingo 20, y los fichajes de los fixtures caen dentro.
 */

uses(RefreshDatabase::class);

const SEMANA_PASADA = '2026-09-14';

/**
 * Un centro con dos departamentos, su responsable de Cocina y una semana
 * fichada.
 *
 * @return array{cocina: int, recepcion: int, manager: int, deCocina: string, deRecepcion: string}
 */
function centroConResponsableDeCocina(): array
{
    $site = WorkforceFixtures::site('Hotel con dos areas');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $deCocina = WorkforceFixtures::employee($site, $cocina, firstName: 'Ana', lastName: 'Cocinera');
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion, firstName: 'Luis', lastName: 'Recepcionista');

    // Martes de la semana pasada, ocho horas cada uno.
    PeriodReportFixtures::workDay($site, $deCocina, '2026-09-15', '2026-09-15 08:00', '2026-09-15 16:00');
    PeriodReportFixtures::workDay($site, $deRecepcion, '2026-09-15', '2026-09-15 08:00', '2026-09-15 16:00');

    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    DB::table('departments')->where('id', $cocina)->update(['manager_user_id' => $manager->id]);

    return [
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'manager' => $manager->id,
        'deCocina' => $deCocina,
        'deRecepcion' => $deRecepcion,
    ];
}

/**
 * Enciende el resumen **por el panel**, que es la unica via de cambio que tiene
 * (RF-PD-01): asi la prueba comprueba de paso que la clave es editable.
 */
function enciendeElResumenSemanal(): void
{
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => [SettingKey::WEEKLY_SUMMARY_EMAIL->value => 'enabled']])
        ->assertStatus(200);

    app()->forgetScopedInstances();
}

/**
 * Una instalacion con el correo de verdad configurado.
 *
 * `MAIL_MAILER` es `array` en la suite, que el caso de uso lee como «esta
 * instalacion no manda correo». Se cambia a `smtp` y se fingen las
 * notificaciones: nada sale a ninguna parte y el camino que se recorre es el
 * completo.
 */
function conSmtpConfigurado(): void
{
    Config::set('mail.default', 'smtp');
    Notification::fake();
}

function pasadaSemanal(?string $week = null): WeeklySummaryPass
{
    FrozenTime::at('2026-09-21 06:00:00');

    return app(SendWeeklySummaries::class)->handle($week);
}

/**
 * El resumen que recibio el responsable, ya renderizado a lineas de texto.
 *
 * @return list<string>
 */
function lineasDelCorreo(): array
{
    $lines = [];

    Notification::assertSentOnDemand(
        WeeklySummaryNotification::class,
        function (WeeklySummaryNotification $notification) use (&$lines): bool {
            $mail = $notification->toMail(new AnonymousNotifiable);

            $lines = array_values(array_map(
                static fn (mixed $line): string => \is_string($line) ? $line : '',
                [$mail->subject, ...$mail->introLines, ...$mail->outroLines],
            ));

            return true;
        },
    );

    return $lines;
}

// --- El alcance (RF-ID-03) ---------------------------------------------------

it('el resumen de un responsable de Cocina no menciona a nadie de Recepcion', function (): void {
    // El escenario «Aislamiento por departamento» del doc 01 §11, aplicado al
    // correo. Aqui tiene una vuelta de tuerca frente al panel: el resumen sale
    // **sin nadie delante** y se envia a una bandeja de entrada que se puede
    // reenviar, asi que un alcance mal aplicado no lo descubre nadie hasta que
    // ya salio de la instalacion.
    $contexto = centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->reason)->toBe(WeeklySummaryReason::Sent)
        ->and($pass->sent)->toBe(1)
        ->and($pass->week)->toBe('2026-W38');

    $lineas = implode("\n", lineasDelCorreo());

    expect($lineas)->toContain('Ana Cocinera')
        ->and($lineas)->not->toContain('Luis Recepcionista')
        // La semana resumida es la ANTERIOR a la pasada, del lunes al domingo.
        ->and($lineas)->toContain('2026-09-14')
        ->and($lineas)->toContain('2026-09-20');

    // Y la fila del envio cuenta a una sola persona: la de su departamento.
    expect(DB::table('weekly_summary_deliveries')
        ->where('manager_user_id', $contexto['manager'])
        ->value('employee_count'))->toBe(1);
})->group('RF-PR-05', 'RF-ID-03');

// --- Que no enviar no es un fallo --------------------------------------------

it('sin transporte de correo la pasada no falla y se omite con registro', function (): void {
    // Doc 02 §11.6.2: hay instalaciones sin salida a internet, y doc 05 §5.7
    // vende el resumen como «correo opcional». `MAIL_MAILER` en `array` —el de
    // la suite— es exactamente ese caso.
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();

    $pass = pasadaSemanal();

    expect($pass->reason)->toBe(WeeklySummaryReason::MailerSilent)
        ->and($pass->sent)->toBe(0)
        ->and(DB::table('weekly_summary_deliveries')->count())->toBe(0);
})->group('RF-PR-05');

it('con el ajuste apagado —que es como se entrega— no envia nada', function (): void {
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->reason)->toBe(WeeklySummaryReason::Disabled)
        ->and($pass->sent)->toBe(0);

    Notification::assertNothingSent();
})->group('RF-PR-05');

it('sin la funcionalidad en la licencia se omite, y sin error', function (): void {
    // Regla dura 15 y ADR-019: lo que se degrada es un correo de gestion. El
    // registro horario y la exportacion para la Inspeccion no dependen de esto.
    centroConResponsableDeCocina();

    LicenseKeys::install();
    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(
            LicenseKeys::current()->issue(['features' => ['advanced_reports']]),
        ),
    );
    app()->forgetInstance(FeatureGate::class);

    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->reason)->toBe(WeeklySummaryReason::NotInPlan)
        ->and($pass->sent)->toBe(0);

    Notification::assertNothingSent();
})->group('RF-PR-05', 'RF-PD-05');

it('sin ningun responsable de departamento no hay a quien escribir', function (): void {
    WorkforceFixtures::site('Hotel sin responsables');

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    expect(pasadaSemanal()->reason)->toBe(WeeklySummaryReason::NoRecipients);
})->group('RF-PR-05');

it('no escribe a rrhh ni a admin, que tienen el panel entero', function (): void {
    // Decision 2 de la ficha: un correo semanal con toda la plantilla seria una
    // copia periodica del registro fuera del sistema, reenviable y sin control
    // de acceso (minimizacion). Y quien tiene los dos roles tampoco: en el panel
    // su alcance es global, asi que un correo acotado a un departamento diria
    // algo distinto de lo que ve en pantalla.
    $contexto = centroConResponsableDeCocina();

    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    DB::table('departments')->where('id', $contexto['recepcion'])->update(['manager_user_id' => $rrhh->id]);

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->recipients)->toBe(1)
        ->and($pass->sent)->toBe(1)
        ->and(DB::table('weekly_summary_deliveries')->where('manager_user_id', $rrhh->id)->exists())
        ->toBeFalse();
})->group('RF-PR-05', 'RF-ID-03');

// --- La constancia (RS-05) ---------------------------------------------------

it('deja en audit_log la divulgacion con el conjunto weekly_summary', function (): void {
    // RS-05 y RL-15. El correo saca nombres de la plantilla de la instalacion
    // por SMTP, y este asiento es lo que permite contestar ante una brecha «que
    // se fue, de quien, a quien y cuando».
    $contexto = centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    pasadaSemanal();

    $payload = DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload', 'like', '%weekly_summary%')
        ->orderByDesc('id')
        ->value('payload');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(is_string($payload) ? $payload : '', true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['dataset'] ?? null)->toBe('weekly_summary')
        ->and($decoded['record_count'] ?? null)->toBe(1)
        // A QUIEN se le mandaron y DE QUE semana: las dos claves que ningun otro
        // asiento de informe tiene, porque en los demas el destinatario es el
        // actor de la peticion.
        ->and($decoded['manager_user_id'] ?? null)->toBe($contexto['manager'])
        ->and($decoded['week_start'] ?? null)->toBe(SEMANA_PASADA)
        // DE QUIEN, por identificador y nunca por nombre (regla dura 21).
        ->and($decoded['employee_uuids'] ?? null)->toBe($contexto['deCocina'])
        ->and($decoded['format'] ?? null)->toBe('mail')
        ->and(json_encode($decoded, JSON_THROW_ON_ERROR))->not->toContain('Cocinera');
})->group('RS-05', 'RF-PR-05');

// --- Idempotencia ------------------------------------------------------------

it('dos pasadas de la misma semana no reenvian el resumen', function (): void {
    // Repetir el comando —o pasar `--week=` de una semana ya enviada— no puede
    // producir un segundo correo con los mismos nombres: dos copias del mismo
    // dato personal fuera del sistema, y un responsable que deja de leer un
    // aviso que llega dos veces.
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    expect(pasadaSemanal()->sent)->toBe(1);

    $segunda = pasadaSemanal();

    expect($segunda->sent)->toBe(0)
        ->and($segunda->skipped)->toBe(1)
        ->and(DB::table('weekly_summary_deliveries')->count())->toBe(1);

    Notification::assertSentOnDemandTimes(WeeklySummaryNotification::class, 1);
})->group('RF-PR-05');

it('permite reenviar a mano una semana concreta que todavia no salio', function (): void {
    // `--week=AAAA-Www`, que es para lo que existe: la semana en la que el SMTP
    // estuvo caido.
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal('2026-W37');

    expect($pass->week)->toBe('2026-W37')
        ->and($pass->sent)->toBe(1)
        ->and(DB::table('weekly_summary_deliveries')->where('week_start', '2026-09-07')->exists())
        ->toBeTrue();
})->group('RF-PR-05');

// --- Privacidad del log (RF-PD-15, regla dura 21) ----------------------------

it('el registro de la pasada lleva recuentos y ningun nombre', function (): void {
    // Este log viaja al fabricante dentro del paquete de diagnostico (ADR-020).
    // Si llevara nombres o direcciones, se habria filtrado la plantilla del
    // cliente por el canal que menos se mira.
    $contexto = centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    /** @var array<string, mixed> $logged */
    $logged = [];

    // `Event::listen` y no `Log::spy()`: el doble del facade deja el contenedor
    // devolviendo `null` por `LoggerInterface`, y medio modulo `Product` se
    // construye con el. Aqui se escucha al logger de verdad.
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[$message->message] = $message->context;
    });

    FrozenTime::at('2026-09-21 06:00:00');

    // Por el comando y no por el caso de uso: el apunte lo escribe el, y es el
    // camino por el que corre de verdad los lunes.
    expect(Artisan::call('reporting:weekly-summary'))->toBe(0)
        ->and($logged)->toHaveKey('reporting.weekly_summary');

    /** @var array<string, mixed> $context */
    $context = $logged['reporting.weekly_summary'];

    expect($context['reason'] ?? null)->toBe('sent')
        ->and($context['sent'] ?? null)->toBe(1)
        ->and($context['week'] ?? null)->toBe('2026-W38');

    $serialized = json_encode($logged, JSON_THROW_ON_ERROR);

    // Ni el nombre de la persona resumida, ni la direccion del responsable, ni
    // siquiera el identificador publico del empleado: el apunte de la pasada son
    // recuentos y un motivo. De quien recibio que datos se mira en `audit_log`,
    // que se queda en el servidor del cliente.
    expect($serialized)->not->toContain('Cocinera')
        ->and($serialized)->not->toContain('@kronoqr.test')
        ->and($serialized)->not->toContain($contexto['deCocina']);
})->group('RF-PD-15', 'RF-PR-05');

// --- Volumen -----------------------------------------------------------------

it('compone el resumen de un departamento de doscientas personas', function (): void {
    // RNF-P: el tope de cincuenta lineas es del correo, no de la consulta. Lo
    // que esta prueba defiende es que el informe cuenta a las doscientas y que
    // el asiento **no** enumera a nadie por encima del corte: enumerarlas
    // convertiria `audit_log` en una segunda copia de la plantilla.
    $site = WorkforceFixtures::site('Hotel grande');
    $cocina = WorkforceFixtures::department($site, 'Cocina');

    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    DB::table('departments')->where('id', $cocina)->update(['manager_user_id' => $manager->id]);

    for ($i = 0; $i < 200; $i++) {
        $uuid = WorkforceFixtures::employee($site, $cocina, firstName: 'Persona', lastName: 'Numero '.$i);
        PeriodReportFixtures::workDay($site, $uuid, '2026-09-15', '2026-09-15 08:00', '2026-09-15 16:00');
    }

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->sent)->toBe(1);

    $fila = DB::table('weekly_summary_deliveries')->where('manager_user_id', $manager->id)->first();

    expect($fila?->employee_count)->toBe(200)
        ->and($fila?->row_count)->toBe(200);

    $payload = DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload', 'like', '%weekly_summary%')
        ->orderByDesc('id')
        ->value('payload');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(is_string($payload) ? $payload : '', true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['employees'] ?? null)->toBe(200)
        // Por encima de cincuenta no se enumera: el recuento y el alcance
        // contestan mejor que una lista de doscientos identificadores.
        ->and($decoded)->not->toHaveKey('employee_uuids');

    // Y el correo detalla cincuenta y dice cuantas faltan.
    $lineas = lineasDelCorreo();

    expect(implode("\n", $lineas))->toContain('150');
})->group('RF-PR-05', 'RNF-P-05');

// --- El orden de la decision 13: ninguna red bajo el candado ----------------

/**
 * Un transporte que nunca entrega, que es lo que hace un relevo mal configurado
 * —el fallo mas comun de una instalacion recien puesta en marcha—.
 */
function conElCorreoRoto(): void
{
    Config::set('mail.default', 'smtp');
    Notification::fake();

    app()->bind(WeeklySummaryMailer::class, fn (): WeeklySummaryMailer => new class implements WeeklySummaryMailer
    {
        public function send(WeeklySummaryRecipient $recipient, WeeklySummary $summary): bool
        {
            return false;
        }
    });
}

it('si el correo no sale no queda ni fila ni asiento, y la pasada devuelve 1', function (): void {
    // Decision 13. El asiento describe una divulgacion **consumada**: anotarlo
    // ante un intento fallido inflaria el alcance de una brecha con datos que no
    // salieron de la instalacion (RL-15). Y la reclamacion se retira, para que
    // la semana siga pendiente y el lunes siguiente —o un `--week` a mano— la
    // reintente.
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conElCorreoRoto();

    $pass = pasadaSemanal();

    expect($pass->failed())->toBe(1)
        ->and($pass->sent)->toBe(0)
        ->and(DB::table('weekly_summary_deliveries')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('payload', 'like', '%weekly_summary%')->count())->toBe(0);

    // Y el comando lo dice con su codigo de salida, que es lo que el
    // planificador convierte en `scheduler.command_failed`.
    FrozenTime::at('2026-09-21 06:00:00');

    expect(Artisan::call('reporting:weekly-summary'))->toBe(1);
})->group('RF-PR-05', 'RS-05');

it('un correo entregado deja exactamente un asiento', function (): void {
    // Ni cero —el asiento es obligatorio cuando los datos salen (regla dura 6)—
    // ni dos: componer el informe para mandarlo y mandarlo son el mismo acto.
    // Con el asiento partido, quien lee el trail tendria que emparejar entradas
    // para contestar una sola pregunta.
    centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    pasadaSemanal();

    expect(DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload', 'like', '%weekly_summary%')
        ->count())->toBe(1);
})->group('RS-05', 'RF-PR-05');

it('perder la carrera del UNIQUE se cuenta como omitido y no como fallo', function (): void {
    // Dos pasadas a la vez —un `cron` duplicado, una ejecucion a mano encima de
    // la programada— no pueden mandar el mismo resumen dos veces, y la segunda
    // tampoco puede terminar en rojo: el correo salio, solo que lo mando la
    // otra. Se reproduce con la fila ya reclamada entre el `wasSent()` y el
    // `claim()`, que es exactamente lo que ve la perdedora.
    $contexto = centroConResponsableDeCocina();

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    app(WeeklySummaryDeliveries::class)->claim(
        $contexto['manager'],
        SEMANA_PASADA,
        new DateTimeImmutable('2026-09-21T06:00:00+00:00'),
        1,
        1,
    );

    $pass = pasadaSemanal();

    expect($pass->skipped)->toBe(1)
        ->and($pass->sent)->toBe(0)
        ->and($pass->failed())->toBe(0)
        ->and(DB::table('weekly_summary_deliveries')->count())->toBe(1);

    Notification::assertNothingSent();
})->group('RF-PR-05');

it('un responsable sin departamentos no recibe nada y no deja asiento', function (): void {
    // Un responsable existe antes de que se le asigne el primer departamento, y
    // en ese hueco **no alcanza a nadie** (RF-ID-03). No hay nada que resumir y,
    // sobre todo, nada que divulgar: ni informe, ni correo, ni asiento.
    WorkforceFixtures::site('Hotel con un responsable recien creado');
    ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    $pass = pasadaSemanal();

    expect($pass->recipients)->toBe(1)
        ->and($pass->skipped)->toBe(1)
        ->and($pass->sent)->toBe(0)
        ->and(DB::table('audit_log')->where('payload', 'like', '%weekly_summary%')->count())->toBe(0);

    Notification::assertNothingSent();
})->group('RF-PR-05', 'RF-ID-03');

it('un alcance sin personas esa semana recibe el correo que lo dice', function (): void {
    // Un departamento recien creado, o uno cuya gente estuvo de vacaciones la
    // semana entera. El alcance lo declaro el cliente al asignar el
    // departamento: callar se leeria como una averia del correo, asi que se
    // manda el resumen diciendo que no hay nadie —y el recuento de incidencias
    // abiertas llega igual, que es justo cuando mas interesa—.
    $site = WorkforceFixtures::site('Hotel con un departamento vacio');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    DB::table('departments')->where('id', $cocina)->update(['manager_user_id' => $manager->id]);

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    expect(pasadaSemanal()->sent)->toBe(1);

    $lineas = implode("\n", lineasDelCorreo());

    expect($lineas)->toContain('2026-09-14')
        ->and($lineas)->toContain('No tienes ninguna incidencia sin resolver')
        // Y la fila deja constancia de que no salio el dato de nadie.
        ->and(DB::table('weekly_summary_deliveries')->where('manager_user_id', $manager->id)->value('employee_count'))
        ->toBe(0);
})->group('RF-PR-05');

it('el fallo de un destinatario no deja sin resumen a los demas', function (): void {
    // Lo que esta prueba impide: que el noveno responsable se quede sin su
    // correo porque el tercero tenia un problema. Cada envio es suyo —su
    // reclamacion, su transaccion y su asiento— y el fallo se queda dentro.
    $contexto = centroConResponsableDeCocina();

    $segundo = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    DB::table('departments')->where('id', $contexto['recepcion'])->update(['manager_user_id' => $segundo->id]);

    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    Config::set('mail.default', 'smtp');
    Notification::fake();

    // Al primero le revienta el envio con una excepcion, que es el camino que NO
    // pasa por el `false` del adaptador; al segundo le sale.
    app()->bind(
        WeeklySummaryMailer::class,
        fn (): WeeklySummaryMailer => new class($contexto['manager']) implements WeeklySummaryMailer
        {
            public function __construct(private readonly int $managerThatFails) {}

            public function send(WeeklySummaryRecipient $recipient, WeeklySummary $summary): bool
            {
                if ($recipient->userId === $this->managerThatFails) {
                    throw new RuntimeException('El relevo de correo no responde.');
                }

                return true;
            }
        },
    );

    $pass = pasadaSemanal();

    expect($pass->recipients)->toBe(2)
        ->and($pass->sent)->toBe(1)
        ->and($pass->failed())->toBe(1)
        ->and($pass->failures[0]['manager_user_id'])->toBe($contexto['manager'])
        // Solo queda la fila del que si salio, y solo su asiento.
        ->and(DB::table('weekly_summary_deliveries')->pluck('manager_user_id')->all())->toBe([$segundo->id])
        ->and(DB::table('audit_log')->where('payload', 'like', '%weekly_summary%')->count())->toBe(1);
})->group('RF-PR-05');

it('una semana mal escrita responde INVALID sin traza', function (): void {
    // Lo escribio una persona en la linea de ordenes: lo util es decirle que la
    // forma es `AAAA-Www`, no volcarle una traza de PHP en el log del
    // planificador.
    LicenseKeys::grantAll();
    enciendeElResumenSemanal();
    conSmtpConfigurado();

    FrozenTime::at('2026-09-21 06:00:00');

    expect(Artisan::call('reporting:weekly-summary', ['--week' => '2026-W99']))->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('AAAA-Www')
        ->and(Artisan::output())->not->toContain('#0 ');
})->group('RF-PR-05');
