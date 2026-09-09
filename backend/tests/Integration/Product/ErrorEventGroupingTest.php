<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La agrupacion por huella **bajo concurrencia**, contra PostgreSQL de verdad
 * (RF-PD-15, §9.5 «toca el esquema»).
 *
 * ## Por que hacen falta procesos y no un bucle
 *
 * Lo que se quiere demostrar es que la garantia la da **el `UNIQUE` de
 * `error_events.fingerprint` y el `ON CONFLICT`**, no el codigo PHP. Cincuenta
 * llamadas seguidas dentro de un proceso pasarian igual con un `SELECT` previo,
 * que es exactamente la implementacion prohibida por tener condicion de carrera.
 * Con procesos de verdad, cada uno abre su transaccion y arbitra el motor.
 *
 * Y el caso no es teorico: *«un fallo en el endpoint de fichaje durante un
 * cambio de turno genera cientos de errores identicos»* (doc 02 §8.2.1).
 * Cincuenta escrituras simultaneas de la misma huella es el escenario normal de
 * esta tabla.
 *
 * ## `CommittedDatabase` y no `RefreshDatabase`
 *
 * Un proceso hijo abre su propia conexion y **no puede ver lo que la transaccion
 * del padre todavia no ha confirmado**. Ademas, aqui se ejercita el cableado de
 * produccion tal cual: la conexion `error_events` es de verdad otra sesion.
 */

uses(CommittedDatabase::class);

/** Un informe con la huella que decida quien llama. */
function informeDeError(string $mensaje, ?string $version = null): ErrorReport
{
    return new ErrorReport(
        source: ErrorSource::Api,
        level: ErrorLevel::Critical,
        message: $mensaje,
        occurredAt: new DateTimeImmutable('now'),
        appVersion: $version ?? '2.2.0',
        context: ['route' => '/api/v1/scan', 'method' => 'POST'],
        code: null,
        exceptionClass: 'RuntimeException',
        file: 'app/Modules/Attendance/Application/UseCase/RecordScan.php',
        line: 88,
        traceId: str_repeat('c', 32),
    );
}

it('cincuenta escrituras simultaneas de la misma huella dejan una fila con occurrences 50', function (): void {
    WorkforceFixtures::site();

    $resultados = ParallelRequests::run(50, static function (int $indice): TestResponse {
        // Cada proceso escribe el MISMO error, con un identificador variable
        // dentro del mensaje: la normalizacion lo borra y la huella coincide.
        app(ErrorEventSink::class)->record(informeDeError(
            'No se pudo procesar el escaneo '.$indice.' desde /var/www/html/storage/app',
        ));

        // `ParallelRequests` espera una respuesta HTTP -esta escrito para las
        // peticiones concurrentes del §9.4- y el padre lee su cuerpo como JSON.
        // Aqui lo que se mide es una escritura, asi que se devuelve un JSON
        // minimo y lo que importa se afirma despues sobre la tabla.
        /** @var TestResponse<Response> $respuesta */
        $respuesta = new TestResponse(new JsonResponse(['index' => $indice]));

        return $respuesta;
    });

    expect($resultados)->toHaveCount(50);

    expect(DB::table('error_events')->count())
        ->toBe(1, 'La agrupacion por huella ha dejado mas de una fila bajo concurrencia.')
        ->and(DB::table('error_events')->where('occurrences', 50)->count())->toBe(1);
})->group('RF-PD-15');

it('un grupo resuelto que vuelve a ocurrir se reabre conservando su recuento', function (): void {
    // «Resuelto» tiene que significar «ya no pasa», no «ya no se ve»: que un
    // fallo dado por arreglado reaparezca es exactamente lo que IT debe ver.
    WorkforceFixtures::site();

    $sumidero = app(ErrorEventSink::class);
    $sumidero->record(informeDeError('la cola no responde'));
    $sumidero->record(informeDeError('la cola no responde'));

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $id = (int) DB::table('error_events')->orderBy('id')->firstOrFail()->id;

    app(ErrorEventRepository::class)->resolve($id, $usuario->id, new DateTimeImmutable('now'));

    expect(DB::table('error_events')->where('id', $id)->value('resolved_at'))->not->toBeNull();

    // Y vuelve a ocurrir, con otra version del producto.
    $sumidero->record(informeDeError('la cola no responde', '2.3.0'));

    $grupo = DB::table('error_events')->where('id', $id);

    expect($grupo->value('resolved_at'))->toBeNull()
        ->and($grupo->value('resolved_by_user_id'))->toBeNull()
        // El recuento NO se reinicia: son tres apariciones del mismo fallo.
        ->and($grupo->where('occurrences', 3)->count())->toBe(1)
        // Y `app_version` es el de la ULTIMA, que es lo que responde a «¿esto
        // sigue pasando despues de actualizar?».
        ->and($grupo->value('app_version'))->toBe('2.3.0');
})->group('RF-PD-15');

it('la escritura sobrevive a la transaccion que fallo', function (): void {
    // La razon de ser de la conexion propia (decision 6): un error ocurrido
    // dentro de una transaccion que despues se revierte tiene que quedar
    // registrado. Con la conexion por defecto, el unico rastro del fallo
    // desapareceria justo por ser un fallo.
    WorkforceFixtures::site();

    DB::beginTransaction();
    app(ErrorEventSink::class)->record(informeDeError('reventado a mitad de la transaccion'));
    DB::rollBack();

    expect(DB::table('error_events')->count())->toBe(1);
})->group('RF-PD-15');

it('la huella que se guarda es la que calcula el dominio', function (): void {
    // Ata la columna a `ErrorFingerprint`: si la escritura calculara la huella
    // por su cuenta, la agrupacion podria funcionar y no coincidir con lo que
    // el panel y el paquete de diagnostico afirman.
    WorkforceFixtures::site();

    app(ErrorEventSink::class)->record(informeDeError('sin nada variable dentro'));

    $esperada = ErrorFingerprint::forServer(
        ErrorSource::Api,
        'RuntimeException',
        'app/Modules/Attendance/Application/UseCase/RecordScan.php',
        88,
        'sin nada variable dentro',
    );

    expect(DB::table('error_events')->value('fingerprint'))->toBe($esperada->value);
})->group('RF-PD-15');
