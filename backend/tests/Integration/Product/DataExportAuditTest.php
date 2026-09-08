<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\UseCase\DownloadDataExportHandler;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Infrastructure\Export\ZipDataExportArchiveWriter;
use App\Modules\Product\Infrastructure\Job\GenerateDataExportJob;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use DateTimeImmutable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Que ha salido de aqui, quien se lo llevo y cuando** (RF-PD-14, RL-20,
 * RS-05, RL-04, regla dura 6).
 *
 * Una exportacion integra es el mayor acceso a datos personales que este
 * producto permite: toda la plantilla, cuatro años de fichajes y las cuentas de
 * gestion en un solo fichero. Los tres asientos son lo que convierte eso en algo
 * defendible ante una inspeccion o ante una brecha (RL-15).
 *
 * ## El asiento dificil es el de la generacion
 *
 * Lo escribe el trabajador de cola, donde **no hay sesion**: sin la decision 7 de
 * la ficha —el evento lleva `requested_by_user_id` y el listener construye el
 * actor con el— saldria firmado como `system`, y responder «¿quien se llevo los
 * datos?» exigiria emparejar a mano dos asientos separados por segundos. Esa es
 * la prueba central de este fichero, y se ejercita **por el trabajo de verdad**,
 * no llamando al caso de uso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-09-08 10:00:00'));
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

/**
 * El asiento de esa accion.
 *
 * **Falla en voz alta si no existe** en lugar de devolver `null`: en este fichero
 * la ausencia de un asiento es siempre el defecto que se busca, y con un tipo
 * anulable cada uso tendria que volver a comprobarlo.
 *
 * @return array{actor_type: string, actor_id: ?int, subject_type: ?string, payload: array<string, mixed>}
 */
function asientoDeExportacion(string $accion): array
{
    $fila = DB::table('audit_log')->where('action', $accion)->orderBy('id')->first();

    expect($fila)->not->toBeNull('Falta el asiento «'.$accion.'» en audit_log.');

    /** @var object{actor_type: string, actor_id: ?int, subject_type: ?string, payload: string} $fila */
    /** @var array<string, mixed> $payload */
    $payload = json_decode($fila->payload, true, 512, JSON_THROW_ON_ERROR);

    return [
        'actor_type' => $fila->actor_type,
        'actor_id' => $fila->actor_id === null ? null : (int) $fila->actor_id,
        'subject_type' => $fila->subject_type,
        'payload' => $payload,
    ];
}

it('atribuye los tres asientos a quien pidio la exportacion, incluida la generacion en la cola', function (): void {
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Panel, $usuario->id),
    );

    /*
     * SE EJECUTA EL TRABAJO DE VERDAD, y no el caso de uso directamente. Es la
     * unica forma de comprobar lo que importa: que el asiento de generacion
     * lleva al autor aunque lo escriba un proceso sin sesion. Llamando al caso de
     * uso desde la prueba habria una sesion en el contenedor y el fallo pasaria
     * desapercibido.
     */
    app()->call([new GenerateDataExportJob($requested->uuid), 'handle']);

    $export = DataExports::find($requested->uuid);

    app(DownloadDataExportHandler::class)->handle(
        uuid: $export->uuid,
        fileExists: static fn (string $path): bool => is_file($path),
        downloadedByUserId: $usuario->id,
    );

    foreach (['data_export.requested', 'data_export.generated', 'data_export.downloaded'] as $accion) {
        $asiento = asientoDeExportacion($accion);

        expect($asiento['actor_type'])->toBe(
            'user',
            'El asiento «'.$accion.'» no se atribuye a una cuenta. En la generacion es la trampa: '
            .'la escribe la cola, sin sesion, y sin el autor dentro del evento saldria como `system`.',
        )
            ->and($asiento['actor_id'])->toBe($usuario->id)
            ->and($asiento['subject_type'])->toBe('data_export')
            ->and($asiento['payload']['data_export_uuid'])->toBe($export->uuid);
    }
})->group('RF-PD-14', 'RS-05', 'RL-04');

it('atribuye a system la exportacion pedida desde la consola', function (): void {
    // Sin nadie detras, `system` es la verdad. Decir «usuario desconocido» seria
    // peor, y atribuirla a una cuenta cualquiera, falso.
    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $pedida = asientoDeExportacion('data_export.requested');
    $generada = asientoDeExportacion('data_export.generated');

    expect($pedida['actor_type'])->toBe('system')
        ->and($pedida['actor_id'])->toBeNull()
        ->and($pedida['payload']['requested_via'])->toBe('console')
        ->and($generada['actor_type'])->toBe('system');
})->group('RF-PD-14', 'RS-05');

it('registra recuentos y huella en el asiento, y nunca el contenido', function (): void {
    // El payload sirve para reconocer ESE fichero mas adelante; el contenido no
    // entra, porque el trail se exporta —dentro de esta misma exportacion— y no
    // hay razon para difundirlo otra vez ahi (regla dura 21).
    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $generada = asientoDeExportacion('data_export.generated');

    expect($generada['payload']['sha256'])->toBe($export->sha256)
        ->and($generada['payload']['size_bytes'])->toBe($export->sizeBytes)
        ->and($generada['payload']['file_name'])->toBe($export->fileName)
        ->and($generada['payload']['row_counts'])->toHaveKey('employees')
        // Ni una ruta del servidor: el asiento se conserva cuatro años y se
        // exporta.
        ->and(json_encode($generada['payload'], JSON_THROW_ON_ERROR))
        ->not->toContain((string) $export->filePath);
})->group('RF-PD-14', 'RS-05', 'RS-08');

it('la purga borra el fichero, marca la fila y NO borra ninguna', function (): void {
    /*
     * Regla dura 5 y RN-13. Es la mitad de la retencion que hace que una copia
     * completa de la plantilla no se quede en el disco para siempre, y la mitad
     * de la trazabilidad que permite responder «¿salio de aqui una copia en
     * marzo?» años despues.
     */
    $vencida = DataExports::completed(expiresAt: new DateTimeImmutable('2026-09-01T00:00:00Z'));
    $vigente = DataExports::completed(expiresAt: new DateTimeImmutable('2036-01-01T00:00:00Z'));

    expect(is_file((string) $vencida->filePath))->toBeTrue();

    $informe = app(PurgeExpiredDataExportsHandler::class)->handle();

    expect($informe->purged)->toBe(1)
        ->and($informe->released)->toBe(0, 'Ninguna estaba atascada: la unica en juego ya habia terminado.')
        // El fichero de la vencida ya no esta; el de la vigente si.
        ->and(is_file((string) $vencida->filePath))->toBeFalse()
        ->and(is_file((string) $vigente->filePath))->toBeTrue();

    // Y NINGUNA FILA SE HA BORRADO.
    expect(DB::table('data_exports')->count())->toBe(2);

    $fila = DB::table('data_exports')->where('uuid', $vencida->uuid)->first();

    expect($fila?->status)->toBe('purged')
        ->and($fila?->purged_at)->not->toBeNull()
        // La ruta se limpia —no hay nada que servir— y el NOMBRE se conserva,
        // que es lo que permite reconocer la exportacion en una conversacion.
        ->and($fila?->file_path)->toBeNull()
        ->and($fila?->file_name)->not->toBeNull()
        ->and($fila?->sha256)->not->toBeNull()
        ->and($fila?->row_counts)->not->toBe('{}');
})->group('RF-PD-14', 'RN-13');

it('marca la fila aunque alguien hubiera borrado el fichero a mano', function (): void {
    // Lo que la fila tiene que reflejar es que ya no se puede descargar, y eso es
    // cierto tanto si lo borro la purga como si lo borro otro. Lo contrario
    // dejaria filas `completed` ofreciendo una descarga que devuelve `404`.
    $vencida = DataExports::completed(expiresAt: new DateTimeImmutable('2026-09-01T00:00:00Z'));

    unlink((string) $vencida->filePath);

    expect(app(PurgeExpiredDataExportsHandler::class)->handle()->purged)->toBe(1);

    expect(DB::table('data_exports')->where('uuid', $vencida->uuid)->value('status'))->toBe('purged');
})->group('RF-PD-14', 'RN-13');

it('la purga no deja ningun asiento de auditoria', function (): void {
    // A proposito: el vencimiento de un plazo ya anunciado no es un hecho con
    // relevancia legal, y una tarea horaria que casi siempre no hace nada
    // llenaria `audit_log` —encadenado bajo el candado global de ADR-010— de
    // ruido que compite con el camino de cada fichaje.
    DataExports::completed(expiresAt: new DateTimeImmutable('2026-09-01T00:00:00Z'));

    $antes = DB::table('audit_log')->count();

    app(PurgeExpiredDataExportsHandler::class)->handle();

    expect(DB::table('audit_log')->count())->toBe($antes);
})->group('RF-PD-14');

it('deja la fila en failed con el codigo del catalogo y sin restos en el disco', function (): void {
    /*
     * El fallo mas probable en produccion: el directorio de exportaciones no se
     * puede escribir. Lo que importa es que la fila lo diga —el panel lo
     * enseña—, que lleve **uno de los cuatro codigos del catalogo** y no la
     * clase ni el mensaje de la excepcion (regla dura 21: un error de base de
     * datos puede llevar el valor de una fila dentro; y el nombre de una clase de
     * PHP no le dice nada a quien lee el panel) y que no quede un
     * `employees.csv` suelto con la plantilla entera.
     *
     * Aqui el codigo correcto es `write_failed`: es el fallo mas frecuente en
     * produccion y el que lleva a mirar `df -h`.
     */
    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    // Un directorio inexistente y no escribible: `/proc` no admite `mkdir`.
    DataExports::useTemporaryPath();
    config(['product.data_export_path' => '/proc/kronoqr-no-escribible']);
    app()->bind(
        DataExportArchiveWriter::class,
        static fn (): ZipDataExportArchiveWriter => new ZipDataExportArchiveWriter('/proc/kronoqr-no-escribible'),
    );

    // El trabajo captura y registra en lugar de propagar: un reintento seria un
    // segundo recorrido completo de la base de datos sin producir nada nuevo.
    app()->call([new GenerateDataExportJob($requested->uuid), 'handle']);

    $fila = DB::table('data_exports')->where('uuid', $requested->uuid)->first();

    expect($fila?->status)->toBe('failed')
        ->and($fila?->failure_reason)->toBe('write_failed')
        ->and($fila?->file_path)->toBeNull()
        // Y no hay asiento de generacion: no se genero nada.
        ->and(DB::table('audit_log')->where('action', 'data_export.generated')->count())->toBe(0);
})->group('RF-PD-14', 'RS-08');

it('el trabajo marca la fila aunque muera sin poder atrapar el fallo', function (): void {
    /*
     * LA SEGUNDA RED DEL BLOQUEANTE. Cuando el trabajo se queda sin tiempo
     * (`$timeout`), sin memoria, o recibe un `SIGTERM` en mitad de un despliegue,
     * el caso de uso **no llega a ejecutar su `catch`**: el proceso muere dentro.
     * Laravel llama entonces a `failed()`, y ahi es donde la fila tiene que dejar
     * de ocupar el turno — o la instalacion se queda con un `409` eterno.
     *
     * Se invoca directamente, que es exactamente lo que hace el trabajador.
     */
    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    // La fila esta `pending`: el trabajo ni siquiera llego a arrancar.
    (new GenerateDataExportJob($requested->uuid))->failed(new MaxAttemptsExceededException('agotado'));

    $fila = DB::table('data_exports')->where('uuid', $requested->uuid)->first();

    expect($fila?->status)->toBe('failed')
        ->and($fila?->failure_reason)->toBe('unexpected')
        ->and($fila?->failed_at)->not->toBeNull();
})->group('RF-PD-14', 'RL-20');

it('el trabajo no reabre ni reescribe una exportacion que ya habia terminado', function (): void {
    // `failed()` puede llegar despues de que el caso de uso ya haya cerrado la
    // fila —el trabajo termino y murio al soltar la conexion—. Marcar entonces
    // convertiria una exportacion descargable en fallida y el cliente perderia su
    // copia sin motivo.
    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    app(GenerateDataExportHandler::class)->handle($requested->uuid);

    (new GenerateDataExportJob($requested->uuid))->failed(new MaxAttemptsExceededException('agotado'));

    expect(DB::table('data_exports')->where('uuid', $requested->uuid)->value('status'))->toBe('completed');
})->group('RF-PD-14', 'RL-20');
