#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Emite una clave de licencia de KronoQR (RF-PD-04, ADR-018).
 *
 * HERRAMIENTA DEL FABRICANTE. No forma parte del producto y no se copia a
 * ninguna imagen: el Dockerfile de PHP hace `COPY backend/ ./` y esto esta
 * fuera (§7.7, RS-08).
 *
 * Uso:
 *
 *     KRONOQR_LICENSE_SECRET_KEY=<hex> php tools/license-issuer/issue.php \
 *         --customer="Hotel Ejemplo, S.L." \
 *         --plan=estandar \
 *         --max-employees=80 \
 *         --max-devices=3 \
 *         --valid-from=2026-09-01 \
 *         --valid-until=2027-08-31 \
 *         --features=advanced_reports,realtime_presence
 *
 * Se valida TODO antes de firmar: los limites son enteros positivos, la vigencia
 * no va hacia atras y las funcionalidades estan en el catalogo de ADR-023. Una
 * clave mal emitida se descubriria en casa del cliente, con la factura ya
 * mandada; aqui cuesta tres comprobaciones. `--force` emite una funcionalidad
 * que esta herramienta todavia no conoce, para cuando el producto vaya por
 * delante de ella.
 *
 * La clave privada llega SIEMPRE por la variable de entorno. No hay opcion de
 * linea de comandos para ella a proposito: los argumentos quedan en el
 * historico del shell y en `ps`.
 *
 * Imprime la clave por la salida estandar y nada mas, para poder canalizarla.
 * Todo lo demas —avisos y resumen— va por la salida de error.
 */

require __DIR__.'/src/LicenseIssuer.php';

use KronoQR\LicenseIssuer\LicenseIssuer;

/**
 * @param  list<string>  $argv
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (\array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];
        }
    }

    return $options;
}

function fail(string $message): never
{
    fwrite(STDERR, 'ERROR: '.$message.PHP_EOL);
    exit(1);
}

/**
 * Un dia completo en UTC. La vigencia empieza a las 00:00:00 del primer dia y
 * termina a las 23:59:59 del ultimo, de modo que «hasta el 31 de agosto»
 * signifique el 31 de agosto entero (regla dura 3: todo en UTC).
 */
function instant(string $day, string $time): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        fail('Las fechas van en formato AAAA-MM-DD. Recibido: '.$day);
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

    if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
        fail('Fecha inexistente: '.$day);
    }

    return $day.'T'.$time.'Z';
}


/**
 * Un entero positivo, o se aborta.
 *
 * `(int) 'ochenta'` es `0`, y un cero en `max_employees` produce una clave
 * **firmada** que el cliente activa y su producto rechaza como `invalid_payload`
 * —el esquema exige positivos—. El fallo se descubre en casa del hotel, con la
 * factura ya emitida. Aqui cuesta una comprobacion.
 */
function positiveInteger(string $option, string $raw): int
{
    if (! ctype_digit($raw) || (int) $raw < 1) {
        fail('--'.$option.' tiene que ser un numero entero mayor que cero. Recibido: '.$raw);
    }

    return (int) $raw;
}

/**
 * Las funcionalidades ACCESORIAS de ADR-023, y ninguna otra.
 *
 * Se valida por dos motivos, y el segundo es el importante:
 *
 *  1. Una errata —`advanced_report` sin la ese— produce una clave que verifica
 *     y **no concede nada**. El cliente activa su renovacion, ve que sus
 *     informes siguen apagados y llama.
 *  2. **El registro legal no tiene nombre en esta lista y no puede tenerlo**
 *     (ADR-023): quien escribiera `--features=clock_in` no estaria apagando el
 *     fichaje —el producto no sabe leerlo— pero si creyendo que puede.
 *
 * `--force` permite emitir un nombre que esta lista todavia no conoce, que es el
 * caso legitimo de una version del producto mas nueva que esta herramienta.
 *
 * @param  list<string>  $features
 * @return list<string>
 */
function knownFeatures(array $features, bool $force): array
{
    // La columna «Degradable» de ADR-023. Se escribe aqui y en el enum `Feature`
    // del producto; las ata `LicenseIssuerRoundTripTest`.
    $catalogue = [
        'advanced_reports',
        'impact_dashboard',
        'payroll_export',
        'weekly_email_summary',
        'realtime_presence',
        'white_label',
        'telemetry',
    ];

    $unknown = array_values(array_diff($features, $catalogue));

    if ($unknown !== [] && ! $force) {
        fail(
            'Estas funcionalidades no existen en el catalogo de ADR-023: '.implode(', ', $unknown).'.'.PHP_EOL
            .'   Validas: '.implode(', ', $catalogue).'.'.PHP_EOL
            .'   Si de verdad quieres emitir una que esta herramienta no conoce todavia, repite con --force.'
        );
    }

    return $features;
}

$secret = getenv('KRONOQR_LICENSE_SECRET_KEY');

if (! \is_string($secret) || trim($secret) === '') {
    fail('Falta KRONOQR_LICENSE_SECRET_KEY. Sacala del gestor de secretos, no del repositorio.');
}

$options = parseOptions($argv);

foreach (['customer', 'plan', 'max-employees', 'max-devices', 'valid-from', 'valid-until'] as $required) {
    if (! isset($options[$required]) || trim($options[$required]) === '') {
        fail('Falta --'.$required.'. Ver la cabecera de este fichero.');
    }
}

$features = knownFeatures(
    array_values(array_filter(array_map('trim', explode(',', $options['features'] ?? '')))),
    isset($options['force']),
);

$maxEmployees = positiveInteger('max-employees', $options['max-employees']);
$maxDevices = positiveInteger('max-devices', $options['max-devices']);

$validFrom = instant($options['valid-from'], '00:00:00');
$validUntil = instant($options['valid-until'], '23:59:59');

if ($validUntil < $validFrom) {
    // El producto rechaza esta clave al activarla, asi que emitirla es entregar
    // algo que no vale. Vale mas descubrirlo aqui.
    fail('La vigencia termina antes de empezar: --valid-from='.$options['valid-from'].' y --valid-until='.$options['valid-until'].'.');
}

$claims = [
    // Identificador de la licencia, para poder hablar de ella por telefono y
    // para que el asiento de `audit_log` del cliente y la factura coincidan.
    'license_id' => $options['license-id'] ?? bin2hex(random_bytes(8)),
    'customer_name' => $options['customer'],
    'plan' => $options['plan'],
    'max_employees' => $maxEmployees,
    'max_devices' => $maxDevices,
    // SOLO funcionalidades ACCESORIAS (ADR-023). El registro legal —fichaje,
    // consulta, portal, exportacion para la Inspeccion, auditoria, copias— no
    // es licenciable y no tiene nombre que poner aqui.
    'features' => $features,
    'valid_from' => $validFrom,
    'valid_until' => $validUntil,
    'issued_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
];

$key = LicenseIssuer::issue($claims, $secret);

fwrite(STDERR, PHP_EOL.'Licencia emitida'.PHP_EOL);
fwrite(STDERR, '  Cliente:  '.$claims['customer_name'].PHP_EOL);
fwrite(STDERR, '  Plan:     '.$claims['plan'].PHP_EOL);
fwrite(STDERR, '  Limites:  '.$claims['max_employees'].' personas, '.$claims['max_devices'].' quioscos'.PHP_EOL);
fwrite(STDERR, '  Vigencia: '.$claims['valid_from'].' -> '.$claims['valid_until'].PHP_EOL);
fwrite(STDERR, '  Funciones accesorias: '.($features === [] ? 'ninguna' : implode(', ', $features)).PHP_EOL);
fwrite(STDERR, '  Huella:   '.substr(hash('sha256', $key), 0, 12).PHP_EOL.PHP_EOL);

fwrite(STDOUT, $key.PHP_EOL);
