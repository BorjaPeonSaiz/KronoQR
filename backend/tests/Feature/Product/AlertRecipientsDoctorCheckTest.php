<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Illuminate\Support\Facades\Config;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` avisa cuando la vigilancia esta encendida y alguno de los
 * tres destinatarios de alerta no tiene a donde recibirlas (RF-PD-13, tarea
 * 3.2, decisiones 5 y 17i).
 *
 * ## Por que hace falta esta comprobacion, y por que papel a papel
 *
 * Los seis destinos —`ALERT_EMAIL_*` y `ALERT_WEBHOOK_*`— nacen vacios porque
 * nada del cliente vive en el repositorio (regla dura 13). Con un destino vacio
 * Alertmanager **no falla**: sencillamente no genera esa entrega.
 *
 * La primera version de esta sonda salia en verde en cuanto UNO de los tres
 * estaba puesto, y la revision de seguridad lo tumbo con el caso normal de una
 * instalacion recien puesta en marcha: con solo `ALERT_EMAIL_IT` relleno,
 * `RoturaDeCadenaDeAuditoria` —destinatario `seguridad`— no llegaba a nadie y
 * `doctor` decia que todo estaba bien. El reparto de destinatarios del doc 01
 * §9.3 no es decorativo, asi que se comprueba papel a papel.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/**
 * Deja el perfil de Compose y los seis destinos como diga la prueba.
 *
 * @param  array<string, string>  $destinos  Claves `it`, `hr`, `security` con sufijo
 *                                           `_webhook` para el segundo camino.
 */
function conAlertas(string $perfiles, array $destinos = []): void
{
    Config::set('observability.alerting.profiles', $perfiles);

    foreach (['it', 'hr', 'security'] as $papel) {
        Config::set('observability.alerting.email.'.$papel, $destinos[$papel] ?? '');
        Config::set('observability.alerting.webhook.'.$papel, $destinos[$papel.'_webhook'] ?? '');
    }
}

/** La comprobacion de destinatarios dentro del informe de `doctor`. */
function comprobacionDeDestinatarios(string $idioma = 'es'): DoctorCheck
{
    $informe = app(RunDoctorHandler::class)->handle($idioma);

    $encontrada = array_values(array_filter(
        $informe->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'mail.alert_recipients',
    ));

    expect($encontrada)->not->toBeEmpty('`doctor` no incluye la comprobacion de destinatarios de alerta.');

    return $encontrada[0];
}

/**
 * Los papeles sin destino que declara el informe.
 *
 * @return list<string>
 */
function papelesSinDestino(DoctorCheck $comprobacion): array
{
    /** @var list<string> $papeles */
    $papeles = $comprobacion->details['recipients_without_destination'] ?? [];

    return $papeles;
}

it('avisa de los tres papeles cuando no hay ningun destino puesto', function (): void {
    conAlertas('observability');

    $comprobacion = comprobacionDeDestinatarios();

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and(papelesSinDestino($comprobacion))->toBe(['it-cliente', 'rrhh', 'seguridad'])
        // Los tres nombrados en el texto, que es lo unico que lee quien lo va a
        // arreglar.
        ->and($comprobacion->summary)->toContain('it-cliente, rrhh, seguridad')
        // Y el «que hacer», que es lo que evita la llamada de telefono
        // (ADR-016): el fabricante no tiene acceso a este servidor.
        ->and($comprobacion->fix)->toContain('ALERT_EMAIL_IT');
})->group('RF-PD-13', 'RF-PD-11');

it('sigue avisando de rrhh y seguridad cuando solo esta puesto el correo de IT', function (): void {
    // EL CASO QUE TUMBO LA PRIMERA VERSION. Es el estado normal de una
    // instalacion recien puesta en marcha, y con el en verde la rotura de la
    // cadena de auditoria —que va a `seguridad`— no la lee nadie.
    conAlertas('observability', ['it' => 'informatica@hotel.example']);

    $comprobacion = comprobacionDeDestinatarios();

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and(papelesSinDestino($comprobacion))->toBe(['rrhh', 'seguridad'])
        ->and($comprobacion->summary)->toContain('rrhh, seguridad')
        ->and($comprobacion->summary)->not->toContain('it-cliente');
})->group('RF-PD-13', 'RF-PD-11');

it('sale en verde con los tres cubiertos, sea por correo o por webhook', function (): void {
    // Un hotel que reciba sus alertas en el chat corporativo esta vigilado.
    // Exigir el correo obligaria a inventarse un buzon a quien ya tiene una via
    // mejor, y una comprobacion que se satisface con un valor falso deja de
    // comprobar nada.
    conAlertas('observability', [
        'it' => 'informatica@hotel.example',
        'hr_webhook' => 'https://chat.hotel.example/hooks/rrhh',
        'security' => 'direccion@hotel.example',
    ]);

    $comprobacion = comprobacionDeDestinatarios();

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and(papelesSinDestino($comprobacion))->toBe([])
        ->and($comprobacion->fix)->toBeNull();
})->group('RF-PD-13');

it('no avisa cuando la vigilancia esta apagada', function (): void {
    // Sin perfil no hay reglas que evaluar ni Alertmanager que enrute: que los
    // destinos esten vacios es coherente, no un descuido. Es una configuracion
    // soportada y esta documentada en `operacion.md`.
    conAlertas('');

    $comprobacion = comprobacionDeDestinatarios();

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('apagada')
        ->and($comprobacion->fix)->toBeNull();
})->group('RF-PD-13');

it('no confunde un perfil que empieza igual con el de observabilidad', function (): void {
    // `str_contains()` daria por buena `observability-lite` y la comprobacion
    // callaria justo en la instalacion mas rara.
    conAlertas('observability-lite');

    expect(comprobacionDeDestinatarios()->status)->toBe(DoctorStatus::Ok);

    // Y una lista con varios perfiles si lo encuentra.
    conAlertas('backup,observability');

    expect(comprobacionDeDestinatarios()->status)->toBe(DoctorStatus::Warning);
})->group('RF-PD-13');

it('no publica ni una direccion de correo ni una URL de webhook en los detalles', function (): void {
    // El informe viaja en el paquete de diagnostico (ADR-020). Una direccion
    // identifica a una persona de la organizacion del cliente y **una URL de
    // webhook es un secreto portador**: quien la tiene puede escribir en ese
    // canal. Lo que se publica son los nombres de papel.
    conAlertas('observability', [
        'it' => 'informatica@hotel.example',
        'hr_webhook' => 'https://chat.hotel.example/hooks/T00/B11/xoxb-secreto',
    ]);

    $detalles = (string) json_encode(comprobacionDeDestinatarios()->details);

    expect($detalles)->not->toContain('informatica@hotel.example')
        ->and($detalles)->not->toContain('@')
        ->and($detalles)->not->toContain('chat.hotel.example')
        ->and($detalles)->not->toContain('xoxb-secreto')
        ->and($detalles)->toContain('seguridad');
})->group('RF-PD-13', 'RS-05');

it('lo dice tambien en ingles', function (): void {
    conAlertas('observability');

    expect(comprobacionDeDestinatarios('en')->summary)
        ->toContain('neither an email address nor a webhook')
        ->toContain('it-cliente, rrhh, seguridad');
})->group('RF-PD-13');
