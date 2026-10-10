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
 * `--features=all` concede el catalogo entero de esta herramienta: es la licencia
 * que se vende hoy (todas las secciones del panel). Se expande AQUI, al firmar,
 * y la clave lleva la lista explicita: una funcionalidad que el producto añada
 * en una version futura no esta en una clave ya emitida y obliga a reemitir.
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
 * La columna «Degradable» de ADR-023: lo que una licencia puede conceder.
 *
 * Esta herramienta es independiente del producto a proposito —no se despliega
 * con el y no carga su codigo—, asi que la lista vive aqui y en el enum
 * `Feature` del producto. Las ata `LicenseIssuerRoundTripTest`: la carga util
 * FIRMADA de una clave emitida con `--features=all` tiene que llevar exactamente
 * los casos del enum, ni uno de mas ni uno de menos.
 *
 * @return list<string>
 */
function featureCatalogue(): array
{
    return [
        'advanced_reports',
        'impact_dashboard',
        'payroll_export',
        'weekly_email_summary',
        'realtime_presence',
        'white_label',
        'telemetry',
    ];
}

/**
 * `--features=all` se expande al catalogo completo en el momento de firmar.
 *
 * `all` no se mezcla con nombres sueltos: `all,payroll_export` no concede nada
 * distinto de `all` y delata una orden mal escrita, asi que se rechaza.
 *
 * Devuelve tambien si hubo expansion, para que el aviso de reemision dependa de
 * lo que se interpreto y no del texto crudo de la opcion (`--features=all,` o
 * `" all "` tambien son `all`).
 *
 * @param  list<string>  $features
 * @return array{features: list<string>, expanded: bool}
 */
function expandAll(array $features): array
{
    if (! \in_array('all', $features, true)) {
        return ['features' => $features, 'expanded' => false];
    }

    if ($features !== ['all']) {
        fail('--features=all no se combina con otros nombres: ya concede el catalogo entero ('.implode(', ', featureCatalogue()).').');
    }

    return ['features' => featureCatalogue(), 'expanded' => true];
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
    $catalogue = featureCatalogue();

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

$requested = expandAll(array_values(array_filter(array_map('trim', explode(',', $options['features'] ?? '')))));

$features = knownFeatures($requested['features'], isset($options['force']));

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

if ($requested['expanded']) {
    // El riesgo de `all`, dicho cuando se emite: congela el catalogo de ESTA
    // herramienta, no el de las versiones futuras del producto.
    fwrite(STDERR, 'Aviso: --features=all concede el catalogo de esta herramienta a fecha de hoy.'.PHP_EOL);
    fwrite(STDERR, '  Una funcionalidad que llegue en una version futura no ira en esta clave: habra que reemitirla.'.PHP_EOL.PHP_EOL);
}

fwrite(STDOUT, $key.PHP_EOL);
