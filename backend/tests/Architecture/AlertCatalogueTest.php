<?php

declare(strict_types=1);

use Tests\Architecture\Support\AlertRules;
use Tests\Architecture\Support\Repo;

/*
 * EL CATALOGO DE ALERTAS DEL DOC 01 §9.3, FILA A FILA (tarea 3.2, decisiones 3,
 * 4 y 9 de la ficha).
 *
 * ## Que se afirma aqui y por que no basta con lo que ya habia
 *
 * `BackupAndAlertingTest` comprueba la NORMA —que ninguna alerta escrita se
 * quede sin umbral, destinatario ni runbook—, y esa comprobacion solo mira lo
 * que existe. La otra mitad del requisito es la que nadie vigilaba: que las
 * ONCE filas del catalogo tengan regla. Una fila sin regla no rompe nada, no
 * aparece en ningun log y no se descubre hasta el dia en que el modo de fallo
 * ocurre y no avisa nadie — que es el unico dia en el que importa.
 *
 * Por eso esta prueba **lee la tabla del §9.3 del documento** en vez de repetir
 * sus once filas aqui: una copia se desincroniza del documento en silencio, y
 * el documento es la fuente (CLAUDE.md, orden de autoridad: doc 01 manda sobre
 * QUE hace el producto).
 *
 * ## Las tres traducciones que hace esta prueba
 *
 * El catalogo esta escrito para personas y las reglas para Prometheus. La
 * correspondencia entre los dos vocabularios es parte de la decision 3 y se
 * declara una sola vez:
 *
 *   Critica / Critica (operaciones) / Critica (seguridad) -> `critical`
 *   Alta                                                  -> `high`
 *   Media                                                 -> `warning`
 *
 *   IT del cliente            -> `it-cliente`
 *   RRHH                      -> `rrhh`
 *   Responsable de seguridad  -> `seguridad`
 *
 * `info` no sale del catalogo: es la severidad de la unica alerta que no avisa
 * a nadie (`VentanaDeMantenimientoActiva`), que existe para inhibir.
 *
 * ## Lo que NO se comprueba aqui
 *
 * Que la expresion dispare al cruzar el umbral y no justo por debajo lo prueba
 * `promtool test rules` con series sinteticas (`ObservabilityToolingTest`
 * comprueba que esa puerta existe y que ninguna regla se queda sin ella). Aqui
 * se comprueba el CATALOGO: que cada modo de fallo publicado tenga alguien a
 * quien avisar y un procedimiento que seguir.
 */

/**
 * Las filas de la tabla del §9.3, ya traducidas al vocabulario de Prometheus.
 *
 * @return list<array{alerta: string, umbral: string, severidad: string, destinatario: string, runbook: string}>
 */
function filasDelCatalogoDeAlertas(): array
{
    $documento = str_replace("\r\n", "\n", Repo::contents('docs/01-especificaciones-proyecto.md'));

    $seccion = strstr($documento, '### 9.3 Alertas mínimas');

    expect($seccion)->toBeString(
        'El §9.3 del doc 01 ha cambiado de titulo: esta prueba lo localiza por el encabezado '
        .'«### 9.3 Alertas mínimas». Sin el, el catalogo se compararia contra una tabla vacia.'
    );

    // Acotado al apartado: `strstr` llega hasta el final del documento y mas
    // abajo hay otras tablas de cinco columnas. Sin este corte, esta prueba
    // leeria filas que no son del catalogo y las daria por alertas.
    $seccion = (string) strstr((string) $seccion."\n### fin\n", "\n### ", true);

    $severidades = ['Crítica' => 'critical', 'Alta' => 'high', 'Media' => 'warning'];
    $destinatarios = [
        'IT del cliente' => 'it-cliente',
        'RRHH' => 'rrhh',
        'Responsable de seguridad' => 'seguridad',
    ];

    preg_match_all(
        '/^\| ([^|]+) \| ([^|]+) \| ([^|]+) \| ([^|]+) \| `([^`]+)` \|$/mu',
        (string) $seccion,
        $coincidencias,
        PREG_SET_ORDER,
    );

    $filas = [];

    foreach ($coincidencias as $fila) {
        $severidad = trim(explode('(', $fila[3])[0]);
        $destinatario = trim($fila[4]);

        $filas[] = [
            'alerta' => trim($fila[1]),
            'umbral' => trim($fila[2]),
            'severidad' => $severidades[$severidad] ?? $severidad,
            'destinatario' => $destinatarios[$destinatario] ?? $destinatario,
            'runbook' => 'docs/runbooks/'.trim($fila[5]),
        ];
    }

    return $filas;
}

/**
 * El catalogo como conjunto de datos de Pest: una fila, un caso.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
function catalogoComoConjuntoDeDatos(): array
{
    $conjunto = [];

    foreach (filasDelCatalogoDeAlertas() as $fila) {
        $conjunto[$fila['alerta']] = [
            $fila['alerta'],
            $fila['severidad'],
            $fila['destinatario'],
            $fila['runbook'],
        ];
    }

    return $conjunto;
}

it('sigue leyendo las once filas del catalogo del §9.3', function (): void {
    // Red de seguridad de la propia prueba. Si la tabla cambia de forma y deja
    // de parsearse, el conjunto de datos de abajo se queda vacio y las once
    // comprobaciones pasan sin comprobar nada: verde absoluto y cero cobertura,
    // que es el peor resultado posible de una suite.
    expect(filasDelCatalogoDeAlertas())->toHaveCount(11);
})->group('RF-PR-04');

it('tiene una regla con la severidad, el destinatario y el runbook de cada fila del catalogo', function (
    string $alerta,
    string $severidad,
    string $destinatario,
    string $runbook,
): void {
    // Doc 01 §9.3: «cada alerta lleva umbral, severidad, destinatario y enlace a
    // su runbook». Esta es la direccion que faltaba: del CATALOGO a las reglas.
    // Una fila publicada sin regla es una promesa al cliente que nadie cumple, y
    // no la detecta ninguna otra prueba porque no hay artefacto que mirar.
    //
    // Se exige "al menos una" y no "exactamente una" a proposito: la fila «Cola
    // offline de un dispositivo | > 50 elementos o > 2 h» son dos condiciones
    // distintas y por tanto dos reglas, y la de la copia de seguridad son cuatro.
    $candidatas = array_values(array_filter(
        AlertRules::all(),
        static fn (array $regla): bool => $regla['severity'] === $severidad
            && $regla['destinatario'] === $destinatario
            && $regla['runbook'] === $runbook,
    ));

    expect($candidatas)->not->toBeEmpty(
        'La fila «'.$alerta.'» del doc 01 §9.3 no tiene ninguna regla de Prometheus con '
        .'severity='.$severidad.', destinatario='.$destinatario.' y runbook_url='.$runbook.'. '
        .'El modo de fallo esta publicado en el catalogo y no avisaria a nadie.'
    );
})->with(catalogoComoConjuntoDeDatos(...))->group('RF-PR-01', 'RF-PR-02', 'RF-PR-04', 'RS-07', 'RF-PD-15');

it('declara cada alerta nueva con la severidad, el destinatario, la espera y el runbook de la decision 3', function (
    string $alerta,
    string $severidad,
    string $destinatario,
    string $espera,
    string $runbook,
): void {
    // La fila del catalogo dice a quien se avisa; la decision 3 dice ademas
    // cuanto se espera antes de avisar, que es la mitad anti-fatiga. Sin el
    // `for` escrito, un quiosco que se reinicia y vuelve en dos minutos
    // despierta al IT del cliente, y a la tercera vez nadie mira las alertas.
    $regla = AlertRules::named($alerta);

    expect($regla)->not->toBeNull(
        'No existe la regla '.$alerta.' en '.AlertRules::DIRECTORY.'/. '
        .'La decision 3 de la ficha 3.2 la nombra literalmente.'
    );
    expect($regla['severity'] ?? '')->toBe($severidad, $alerta.' cambia de severidad.');
    expect($regla['destinatario'] ?? '')->toBe($destinatario, $alerta.' cambia de destinatario.');
    expect($regla['for'] ?? '')->toBe($espera, $alerta.' cambia su espera anti-fatiga.');
    expect($regla['runbook'] ?? '')->toBe($runbook, $alerta.' apunta a otro procedimiento.');
})->with([
    // Quiosco (fila «Quiosco sin latido» y fila «Cola offline de un dispositivo»).
    'quiosco sin latido' => ['QuioscoSinLatido', 'critical', 'it-cliente', '5m', 'docs/runbooks/quiosco-no-responde.md'],
    'cola por encima de 50' => ['ColaOfflineAtascada', 'high', 'it-cliente', '5m', 'docs/runbooks/cola-offline-atascada.md'],
    'cola que no se vacia en 2 h' => ['ColaOfflineSinVaciar', 'high', 'it-cliente', '5m', 'docs/runbooks/cola-offline-atascada.md'],
    // API (filas «Tasa de error 5xx» y «Latencia p95» del endpoint de fichaje).
    'errores 5xx en el fichaje' => ['ErroresDeServidorEnElFichaje', 'critical', 'it-cliente', '1m', 'docs/runbooks/errores-en-el-panel.md'],
    'latencia p95 del fichaje' => ['LatenciaDelFichajeAlta', 'high', 'it-cliente', '1m', 'docs/runbooks/errores-en-el-panel.md'],
    'sonda del borde caida' => ['SondaDelBordeFallida', 'critical', 'it-cliente', '5m', 'docs/runbooks/errores-en-el-panel.md'],
    // TLS (fila «Certificado TLS próximo a expirar») y su gemela ya caducada.
    'certificado a menos de 21 dias' => ['CertificadoTlsProximoACaducar', 'high', 'it-cliente', '1h', 'docs/runbooks/renovacion-certificado-tls.md'],
    'certificado caducado' => ['CertificadoTlsCaducado', 'critical', 'it-cliente', '5m', 'docs/runbooks/renovacion-certificado-tls.md'],
    // Anfitrion (fila «Espacio en disco») y el silencio que la protege.
    'disco por debajo del 20 por ciento' => ['EspacioEnDiscoBajo', 'high', 'it-cliente', '15m', 'docs/runbooks/espacio-en-disco.md'],
    'sin metricas del anfitrion' => ['MetricasDelAnfitrionAusentes', 'warning', 'it-cliente', '30m', 'docs/runbooks/espacio-en-disco.md'],
    // Mantenimiento: la unica que no avisa a nadie (decision 6).
    'ventana de mantenimiento' => ['VentanaDeMantenimientoActiva', 'info', 'it-cliente', '0s', 'docs/runbooks/actualizacion-cliente.md'],
    // Quien vigila al vigilante (decision 17c). Sin estas dos, Alertmanager
    // podia estar caido con la copia fallando y la cadena rota a la vez, y nadie
    // lo sabria: las once alertas se evaluan y ninguna se entrega.
    'enrutado de alertas caido' => ['EnrutadoDeAlertasCaido', 'critical', 'it-cliente', '5m', 'docs/runbooks/entrega-de-alertas.md'],
    'entrega de alertas fallando' => ['EntregaDeAlertasFallando', 'high', 'it-cliente', '5m', 'docs/runbooks/entrega-de-alertas.md'],
])->group('RF-AT-10', 'RN-15', 'RNF-D-01', 'RF-PD-10');

it('escribe en cada alerta nueva el umbral literal que publica el catalogo', function (string $alerta, string $fragmento): void {
    // El umbral es lo que convierte una alerta en una promesa comprobable: «>
    // 10 min sin latido», «> 1 % en 5 min», «< 21 dias», «< 20 %». Si la
    // expresion deja de contenerlo, la alerta sigue existiendo, sigue teniendo
    // runbook y sigue pasando la norma del §8.4 — y avisa de otra cosa.
    $regla = AlertRules::named($alerta);

    expect($regla)->not->toBeNull('No existe la regla '.$alerta.'.');

    // Se comparan las dos partes sin espacios: una expresion larga se escribe en
    // YAML en varias lineas y con sangria, y eso es formato, no umbral. Una
    // prueba que distinguiera `> 600` de `>\n  600` mediria el estilo del
    // fichero en vez de la condicion que dispara la alerta.
    expect(preg_replace('/\s+/', '', $regla['expr'] ?? ''))
        ->toContain((string) preg_replace('/\s+/', '', $fragmento));
})->with([
    'latido: la serie del quiosco' => ['QuioscoSinLatido', 'kiosk_last_seen_seconds'],
    'latido: los 600 segundos' => ['QuioscoSinLatido', '> 600'],
    'cola: la serie de la cola' => ['ColaOfflineAtascada', 'kiosk_offline_queue_size'],
    'cola: los 50 elementos' => ['ColaOfflineAtascada', '> 50'],
    'cola: la ventana de dos horas' => ['ColaOfflineSinVaciar', '[2h]'],
    'cola: sin vaciarse ni una vez' => ['ColaOfflineSinVaciar', 'min_over_time'],
    '5xx: acotada al endpoint de fichaje' => ['ErroresDeServidorEnElFichaje', 'attendance'],
    '5xx: solo respuestas de servidor' => ['ErroresDeServidorEnElFichaje', '5..'],
    '5xx: el uno por ciento' => ['ErroresDeServidorEnElFichaje', '> 0.01'],
    'latencia: el percentil 95' => ['LatenciaDelFichajeAlta', 'histogram_quantile(0.95'],
    'latencia: los 500 ms' => ['LatenciaDelFichajeAlta', '> 0.5'],
    'sonda: el borde no responde' => ['SondaDelBordeFallida', 'probe_success == 0'],
    'tls: la serie de la sonda' => ['CertificadoTlsProximoACaducar', 'probe_ssl_earliest_cert_expiry'],
    'tls: los 21 dias' => ['CertificadoTlsProximoACaducar', '21'],
    // DECISION 17(h): el suelo. Sin el, un certificado ya caducado enciende las
    // DOS alertas de TLS a la vez y quien las recibe pierde el tiempo decidiendo
    // cual mira primero.
    'tls: sin solaparse con la de caducado' => ['CertificadoTlsProximoACaducar', '>= 0'],
    'tls: el certificado ya caducado' => ['CertificadoTlsCaducado', 'probe_ssl_earliest_cert_expiry'],
    'disco: el espacio libre del anfitrion' => ['EspacioEnDiscoBajo', 'node_filesystem_avail_bytes'],
    'disco: el tamaño total' => ['EspacioEnDiscoBajo', 'node_filesystem_size_bytes'],
    'disco: el veinte por ciento' => ['EspacioEnDiscoBajo', '< 0.20'],
    'anfitrion: ausencia de la serie' => ['MetricasDelAnfitrionAusentes', 'absent('],
    'mantenimiento: la marca del actualizador' => ['VentanaDeMantenimientoActiva', 'kronoqr_maintenance_active'],
    'mantenimiento: la marca de tiempo' => ['VentanaDeMantenimientoActiva', 'kronoqr_maintenance_since_timestamp_seconds'],
    // DECISION 17(f): el tope baja de 4 h a 2 h. Quien pueda escribir en
    // `BACKUP_PATH/metrics` (uid 1000) puede refrescar la marca y callar
    // `kiosk|api|tls|host`; una actualizacion real se mide en minutos, asi que
    // la ventana de abuso se reduce a la mitad sin coste operativo.
    'mantenimiento: el tope de dos horas' => ['VentanaDeMantenimientoActiva', '2 * 3600'],
    // Quien vigila al vigilante (decision 17c).
    'alertmanager: el objetivo de scrape' => ['EnrutadoDeAlertasCaido', 'up{job="alertmanager"}'],
    'alertmanager: tambien si la serie desaparece' => ['EnrutadoDeAlertasCaido', 'absent('],
    'alertmanager: notificaciones fallidas' => ['EntregaDeAlertasFallando', 'alertmanager_notifications_failed_total'],
])->group('RF-AT-10', 'RNF-D-01', 'RF-PD-10');

it('vigila el silencio del quiosco con los mismos segundos que kiosk:health', function (): void {
    // DECISION 9. Los 600 segundos estan escritos en TRES sitios: la regla de
    // Prometheus, el valor de serie de `config/kiosk.php` y el `.env.example`.
    // Si alguien mueve uno, `kiosk:health` dice que un quiosco esta callado y
    // Prometheus dice que no —o al reves—, y el IT del cliente deja de creerse
    // al que le lleve la contraria. Nada falla; solo dejan de coincidir.
    //
    // Se leen con expresion regular y no con `config()` a proposito: esta suite
    // corre sin arrancar Laravel, y lo que interesa es el valor DE SERIE, el que
    // tendra la instalacion que no toque nada.
    $configuracion = Repo::contents('backend/config/kiosk.php');
    $entorno = Repo::contents('.env.example');

    expect($configuracion)->toMatch("/'silent_after_seconds'\s*=>\s*\(int\) env\('KIOSK_HEALTH_SILENT_AFTER_SECONDS', 600\)/");
    expect($entorno)->toMatch('/^KIOSK_HEALTH_SILENT_AFTER_SECONDS=600\b/m');

    $regla = AlertRules::named('QuioscoSinLatido');

    expect($regla)->not->toBeNull('No existe la regla QuioscoSinLatido.');
    // El mensaje va en `toBeTrue` y no en `toContain`: Pest trata el segundo
    // argumento de `toContain` como otro fragmento que buscar, no como el texto
    // del fallo, y la prueba se volveria contra si misma.
    expect(str_contains($regla['expr'] ?? '', '> 600'))->toBeTrue(
        'La alerta del quiosco sin latido y KIOSK_HEALTH_SILENT_AFTER_SECONDS tienen que valer lo mismo.'
    );
})->group('RF-AT-10', 'RF-PD-13');

it('raspa al propio Alertmanager, sin lo cual la alerta que lo vigila no se evalua', function (): void {
    // DECISION 17(c). `EnrutadoDeAlertasCaido` mira `up{job="alertmanager"}`, y
    // esa serie solo existe si Prometheus tiene el objetivo declarado. Sin el
    // `job`, la regla se evalua siempre a nada y el vigilante del vigilante es
    // un fichero de texto — el mismo modo de fallo mudo que la revision
    // encontro en el contenedor.
    expect(Repo::contents('infra/observability/prometheus/prometheus.yml'))
        ->toContain('job_name: alertmanager');
})->group('RNF-D-01', 'RF-PR-04');

/**
 * Las filas de la tabla de alertas de una guia de operacion, indexadas por
 * nombre de alerta.
 *
 * Una fila puede nombrar dos alertas separadas por `/` —las dos mitades de la
 * copia de seguridad, los dos silencios de la deteccion—: se desdobla, porque lo
 * que se compara es alerta a alerta.
 *
 * @return array<string, array{severidad: string, destinatario: string, runbook: string}>
 */
function filasDeLaGuiaDeOperacion(string $guia): array
{
    $seccion = (string) strstr(
        str_replace("\r\n", "\n", Repo::contents($guia))."\n## fin\n",
        '10.4 ',
    );
    $seccion = (string) strstr($seccion."\n## fin\n", "\n## ", true);

    preg_match_all(
        '/^\|((?: `[A-Za-z]+` \/?)+)\| [^|]+\| ([^|]+)\| ([^|]+)\| \[`[^`]+`\]\(([^)]+)\)[^|]*\|/mu',
        $seccion,
        $coincidencias,
        PREG_SET_ORDER,
    );

    $filas = [];

    foreach ($coincidencias as $fila) {
        preg_match_all('/`([A-Za-z]+)`/', $fila[1], $nombres);

        foreach ($nombres[1] as $alerta) {
            $filas[$alerta] = [
                'severidad' => trim($fila[2]),
                'destinatario' => trim($fila[3]),
                'runbook' => 'docs/runbooks/'.basename(trim($fila[4])),
            ];
        }
    }

    return $filas;
}

/**
 * En que se separa la guia de lo que las reglas evaluan de verdad, alerta a
 * alerta.
 *
 * Devuelve una lista de frases y no lanza: la prueba afirma que la lista esta
 * vacia, y asi un cambio que desalinee cinco filas las enseña las cinco de una
 * vez en lugar de obligar a descubrirlas de una en una.
 *
 * @param  array<string, array{severidad: string, destinatario: string, runbook: string}>  $filas
 * @param  array<string, string>  $severidades  Palabra de la guia -> etiqueta de la regla.
 * @param  array<string, string>  $destinatarios
 * @return list<string>
 */
function desviacionesEntreGuiaYReglas(array $filas, array $severidades, array $destinatarios): array
{
    $desviaciones = [];

    foreach (AlertRules::all() as $regla) {
        $fila = $filas[$regla['alert']] ?? null;

        if ($fila === null) {
            $desviaciones[] = $regla['alert'].': la guia no la menciona (esta en '.$regla['file'].').';

            continue;
        }

        $desviaciones = array_merge(
            $desviaciones,
            desviacionesDeUnaFila($regla, $fila, $severidades, $destinatarios),
        );
    }

    return $desviaciones;
}

/**
 * Lo que una fila de la guia dice de mas o de menos sobre su regla.
 *
 * @param  array{file: string, alert: string, expr: string, for: string, severity: string, destinatario: string, component: string, runbook: string}  $regla
 * @param  array{severidad: string, destinatario: string, runbook: string}  $fila
 * @param  array<string, string>  $severidades
 * @param  array<string, string>  $destinatarios
 * @return list<string>
 */
function desviacionesDeUnaFila(array $regla, array $fila, array $severidades, array $destinatarios): array
{
    $desviaciones = [];

    $severidad = $severidades[trim(explode('(', $fila['severidad'])[0])] ?? $fila['severidad'];

    if ($severidad !== $regla['severity']) {
        $desviaciones[] = $regla['alert'].': la guia dice «'.$fila['severidad'].'» y la regla '.$regla['severity'].'.';
    }

    if ($fila['runbook'] !== $regla['runbook']) {
        $desviaciones[] = $regla['alert'].': la guia enlaza '.$fila['runbook'].' y la regla '.$regla['runbook'].'.';
    }

    // El guion largo significa «no avisa a nadie», y lo que lo hace cierto es que
    // la severidad sea `info`: es la unica que encamina al receptor `silencio`.
    if ($fila['destinatario'] === '—') {
        return $regla['severity'] === 'info'
            ? $desviaciones
            : [...$desviaciones, $regla['alert'].': la guia dice que no avisa a nadie y la regla no es informativa.'];
    }

    $destinatario = $destinatarios[$fila['destinatario']] ?? $fila['destinatario'];

    if ($destinatario !== $regla['destinatario']) {
        $desviaciones[] = $regla['alert'].': la guia avisa a «'.$fila['destinatario'].'» y la regla a '.$regla['destinatario'].'.';
    }

    return $desviaciones;
}

it('publica en la guia de operacion todas las alertas que Prometheus evalua, con lo que dicen las reglas', function (
    string $guia,
    string $critica,
    string $alta,
    string $media,
    string $info,
    string $it,
    string $rrhh,
    string $seguridad,
): void {
    // DECISION 17(k), y el motivo por el que hace falta atarlo con una prueba:
    // la tabla del §10.4 se anuncia como «el catalogo COMPLETO», y la revision
    // la encontro con tres alertas de menos y dos severidades mal. Quien la lee
    // es el IT del cliente a las 06:30, con una notificacion delante que no
    // aparece en la tabla — y entonces la guia deja de merecer confianza entera,
    // no solo en esa fila.
    //
    // Se compara contra `rules/*.yml`, que es lo que de verdad se evalua, y en
    // las dos lenguas: una traduccion que se queda corta es el mismo problema
    // para quien solo lee esa.
    $severidades = [$critica => 'critical', $alta => 'high', $media => 'warning', $info => 'info'];
    $destinatarios = [$it => 'it-cliente', $rrhh => 'rrhh', $seguridad => 'seguridad'];

    $filas = filasDeLaGuiaDeOperacion($guia);

    expect(\count($filas))->toBeGreaterThan(
        15,
        'La tabla de alertas de '.$guia.' ha dejado de parsearse: esta prueba compararia contra el vacio.'
    );

    expect(desviacionesEntreGuiaYReglas($filas, $severidades, $destinatarios))->toBe([]);
})->with([
    'guia de operacion' => ['docs/cliente/operacion.md', 'Crítica', 'Alta', 'Media', 'Info', 'IT', 'RRHH', 'Seguridad'],
    'operation guide' => ['docs/cliente/en/operation.md', 'Critical', 'High', 'Medium', 'Info', 'IT', 'HR', 'Security'],
])->group('RF-PD-02', 'RL-21', 'RF-PR-04');

it('deja escritos los cuatro runbooks que el indice todavia debia a la tarea 3.2', function (): void {
    // Doc 02 §8.4: una alerta sin procedimiento asociado es ruido y se elimina.
    // Las cuatro alertas nuevas del catalogo llegan con su runbook o no llegan.
    // El indice de la carpeta es la otra mitad: si sigue diciendo «Fase 3 ·
    // tarea 3.2» despues de la tarea 3.2, el fichero existe y nadie lo
    // encuentra, que a las 06:30 es exactamente lo mismo que no existir.
    foreach ([
        'quiosco-no-responde.md',
        'cola-offline-atascada.md',
        'renovacion-certificado-tls.md',
        'espacio-en-disco.md',
    ] as $runbook) {
        expect(is_file(Repo::file('docs/runbooks/'.$runbook)))->toBeTrue(
            'Falta docs/runbooks/'.$runbook.', que el catalogo del §9.3 enlaza.'
        );
    }

    expect(str_contains(Repo::contents('docs/runbooks/README.md'), '| Fase 3 · tarea 3.2 |'))->toBeFalse(
        'El indice de runbooks sigue dando por pendiente algo que la tarea 3.2 ya escribio.'
    );
})->group('RF-PR-04', 'RNF-D-01');
