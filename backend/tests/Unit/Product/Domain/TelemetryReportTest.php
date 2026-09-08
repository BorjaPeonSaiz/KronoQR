<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\TelemetryReport;
use App\Modules\Product\Domain\ValueObject\TelemetryScaleBand;
use App\Modules\Product\Domain\ValueObject\TelemetryUsage;
use Tests\Architecture\Support\Repo;

/*
 * La lista cerrada de lo que sale de la instalacion (**RF-PD-12**, ADR-020).
 *
 * ## Por que esta prueba lee un fichero de documentacion
 *
 * Porque la ficha 5.10 exige que el contenido este documentado campo a campo en
 * `docs/cliente/configuracion.md` «para que el cliente pueda decidir con la
 * lista delante», y una lista en un documento se queda vieja el primer dia que
 * alguien añade un campo con prisa. Aqui la tabla del documento **es** la
 * especificacion: si el codigo y el documento se separan, falla la CI y no un
 * cliente leyendo un manual que ya no describe su instalacion.
 *
 * Es el mismo mecanismo que `tests/Architecture/LicenseBoundaryTest.php` usa
 * para atar el enum `Feature` a la tabla de ADR-023.
 *
 * ## Tres listas, no dos
 *
 * 1. La tabla del documento del cliente.
 * 2. La constante `TelemetryReport::FIELDS`.
 * 3. Los campos que el documento produce **de verdad**, recorriendo su array.
 *
 * Con solo las dos primeras, alguien podria añadir una clave en `toArray()` y no
 * enterarse nadie. Es exactamente el fallo del que ADR-020 protege.
 */

/**
 * Los campos de la tabla «Campo | Ejemplo | Que es» de la seccion 3 quinquies.
 *
 * @return list<string>
 */
function camposDocumentados(): array
{
    $doc = Repo::contents('docs/cliente/configuracion.md');

    $start = strpos($doc, '## 3 quinquies. Telemetría');

    expect($start)->not->toBeFalse(
        'configuracion.md ha perdido la seccion «3 quinquies. Telemetria», que es donde el cliente '
        .'lee, campo a campo, lo que sale de su instalacion (ficha 5.10 punto 8).'
    );

    $header = strpos($doc, '| Campo | Ejemplo | Qué es |', (int) $start);

    expect($header)->not->toBeFalse('La seccion 3 quinquies ha perdido su tabla campo a campo.');

    $fields = [];

    foreach (explode("\n", substr($doc, (int) $header)) as $index => $line) {
        // La cabecera y su separador `| --- |`.
        if ($index < 2) {
            continue;
        }

        if (! str_starts_with($line, '|')) {
            break;
        }

        $cells = explode('|', $line);
        $cell = trim($cells[1]);
        $fields[] = trim($cell, '` ');
    }

    return $fields;
}

it('la tabla del documento del cliente es exactamente el catalogo del codigo', function (): void {
    expect(camposDocumentados())->toBe(
        TelemetryReport::FIELDS,
        'La tabla de `configuracion.md` §3 quinquies y `TelemetryReport::FIELDS` han dejado de coincidir. '
        .'La lista es lo que el cliente lee para decidir si activa la telemetria: si el codigo envia algo '
        .'que la tabla no nombra, la decision se tomo sobre informacion falsa (RF-PD-12, ADR-020).'
    );
})->group('RF-PD-12', 'RL-19');

it('el documento produce exactamente los campos del catalogo y ninguno mas', function (): void {
    // La tercera lista. Sin esta, una clave añadida en `toArray()` y olvidada en
    // la constante saldria de la instalacion sin que nadie lo notara.
    expect(informeDeMuestra()->fieldNames())->toBe(TelemetryReport::FIELDS);
})->group('RF-PD-12');

it('el catalogo no admite nada que identifique a nadie', function (string $prohibido): void {
    // Una red de seguridad legible: si alguien añadiera `license.customer_name`
    // o `scale.employees` a la tabla Y a la constante -que es lo que haria falta
    // para colarlo-, esto lo para igual.
    foreach (TelemetryReport::FIELDS as $field) {
        expect(str_contains($field, $prohibido))->toBeFalse(
            'El campo `'.$field.'` contiene «'.$prohibido.'». El documento de telemetria no lleva nada '
            .'que identifique a una persona, a un cliente ni a una instalacion mas alla de su '
            .'`installation_id` aleatorio (ficha 5.10 punto 8, regla dura 21).'
        );
    }
})->with([
    'nombre', 'name', 'email', 'uuid', 'customer', 'license_id', 'fingerprint',
    'path', 'url', 'employee_code', 'hostname', 'ip',
])->group('RF-PD-12', 'RL-19');

/** Un informe con todos los campos rellenos, para recorrer su forma. */
function informeDeMuestra(): TelemetryReport
{
    return new TelemetryReport(
        installationId: '9f2c7b41-0f6a-4a1e-9d54-6b0f3a2c81de',
        sentAt: new DateTimeImmutable('2026-09-08T05:40:12.004311Z'),
        productVersion: '2.1.0',
        phpVersion: '8.4.24',
        databaseVersion: '17.11',
        licenseState: 'active',
        licensePlan: 'estandar',
        licenseFeatures: ['advanced_reports', 'telemetry'],
        daysUntilExpiry: 114,
        employeesActive: TelemetryScaleBand::UpTo250,
        devicesActive: 3,
        departments: 6,
        usage: new TelemetryUsage(4812, 37, 1204, 2, 9),
        doctor: ['database.connection' => 'ok', 'license.state' => 'warning'],
    );
}

it('la plantilla viaja por tramos y nunca como cifra', function (int $personas, string $tramo): void {
    // La cifra exacta permite reconocer a un cliente concreto entre los pocos que
    // tienen ese tamano; el tramo responde igual de bien a la unica pregunta que
    // la telemetria hace (ficha 5.10 punto 8).
    expect(TelemetryScaleBand::of($personas)->value)->toBe($tramo);
})->with([
    'sin plantilla' => [0, '0'],
    'negativo imposible' => [-3, '0'],
    'la primera persona' => [1, '1-25'],
    'justo en el corte' => [25, '1-25'],
    'el siguiente tramo' => [26, '26-100'],
    'cien' => [100, '26-100'],
    'ciento uno' => [101, '101-250'],
    'doscientos cincuenta' => [250, '101-250'],
    'doscientos cincuenta y uno' => [251, '251-500'],
    'quinientos' => [500, '251-500'],
    'una cadena' => [501, '501+'],
    'una cadena grande' => [4000, '501+'],
])->group('RF-PD-12');

it('el documento no lleva ni una hora, ni un uuid ajeno, ni un nombre', function (): void {
    $json = informeDeMuestra()->toJson();

    // `sent_at` es el unico instante, y es el del propio envio.
    expect(preg_match_all('/\d{4}-\d{2}-\d{2}T/', $json))->toBe(1)
        // Y un solo UUID: el de la instalacion.
        ->and(preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $json))->toBe(1)
        ->and($json)->not->toContain('@');
})->group('RF-PD-12', 'RL-19');

it('un contador sin linea de salida no se inventa: va vacio', function (): void {
    // «Si una serie no existe, `null`, nunca inventar». Un `0` afirmaria que no
    // hubo ni un fichaje en una semana, que es una noticia y seria falsa.
    expect(TelemetryUsage::delta(['scans_accepted' => 10], [], 'scans_accepted'))->toBeNull()
        ->and(TelemetryUsage::delta([], ['scans_accepted' => 10], 'scans_accepted'))->toBeNull()
        ->and(TelemetryUsage::delta(['scans_accepted' => 10], ['scans_accepted' => 4], 'scans_accepted'))->toBe(6)
        // Sin actividad en la semana, `0` SI es la verdad: hay con que comparar.
        ->and(TelemetryUsage::delta(['scans_accepted' => 4], ['scans_accepted' => 4], 'scans_accepted'))->toBe(0)
        // Redis es tambien la cache y puede perder sus claves: el acumulado baja
        // y la diferencia seria negativa. Un «-84.000 escaneos» es peor que un hueco.
        ->and(TelemetryUsage::delta(['scans_accepted' => 2], ['scans_accepted' => 900], 'scans_accepted'))->toBeNull();
})->group('RF-PD-12');
