<?php

declare(strict_types=1);

use App\Modules\Workforce\Infrastructure\Adapter\HashedEmployeePinVerifier;
use App\Support\Version\DeployedVersion;
use Tests\Architecture\Support\Repo;

/*
 * Las puertas de calidad de la Fase 0, comprobadas como pruebas.
 *
 * Por que existe este fichero. Los requisitos de la Fase 0 no describen
 * comportamiento del producto: describen que la cadena de herramientas esta
 * puesta y bloquea. Sin una prueba que lo afirme, `qa:traceability --check`
 * los ve como requisitos implementados sin cobertura —y tiene razon—: que la
 * cadena funcione hoy en el portatil de quien la monto no es evidencia de nada.
 *
 * Lo que se comprueba aqui es la CONFIGURACION, no el resultado de ejecutarla.
 * Que PHPStan pase en nivel 9 lo dice `make quality`; que nadie haya bajado el
 * nivel a 5 en un momento de prisa lo dice esta prueba. Son cosas distintas y
 * la segunda es la que se erosiona sin que nadie se entere.
 */

/**
 * Ruta absoluta de un fichero del repositorio, relativa a su raiz.
 *
 * La resolucion de la raiz vive en Tests\Architecture\Support\Repo, que la
 * comparte con las demas pruebas que leen ficheros de fuera de backend/.
 */
function repoFile(string $relative): string
{
    return Repo::file($relative);
}

function repoContents(string $relative): string
{
    expect(is_file(Repo::file($relative)))->toBeTrue($relative.' no existe en el repositorio.');

    return Repo::contents($relative);
}

it('exige PHPStan en el nivel maximo', function (): void {
    // RNF-M-02. El nivel 9 es el maximo de PHPStan y el umbral del §9.2 es 0
    // errores. Un `level: 5` pasaria igual de verde y no verificaria lo mismo.
    expect(repoContents('backend/phpstan.neon'))
        ->toMatch('/^\s*level:\s*9\s*$/m');
})->group('RNF-M-02');

it('exige justificacion en cada supresion de PHPStan', function (): void {
    // RNF-M-02, segunda mitad: "sin errores suprimidos sin justificar". PHPStan
    // obliga a nombrar el identificador del error tras la anotacion de
    // supresion, pero no puede exigir el motivo; eso lo comprueba Semgrep, y
    // esta prueba comprueba que esa regla de Semgrep sigue existiendo.
    //
    // El nombre de esa anotacion no se escribe aqui a proposito: PHPStan la
    // interpreta como directiva aunque este dentro de un comentario, y el
    // analisis falla con un error de sintaxis que no dice de donde viene.
    expect(repoContents('.semgrep/kronoqr-php.yaml'))
        ->toContain('kronoqr-phpstan-ignore-sin-justificacion');
})->group('RNF-M-02');

it('exige los umbrales de cobertura del dominio y del backend', function (): void {
    // RNF-M-01: dominio >= 90 %, global >= 75 % (§9.2). Son DOS umbrales, y el
    // `--min` de Pest es uno solo y global: sin la segunda pasada acotada a
    // Modules/*/Domain, el 90 % del dominio lo podia pagar cualquier otra parte
    // del arbol y la puerta figuraba en verde sin comprobar lo que dice.
    $makefile = repoContents('Makefile');

    expect($makefile)->toMatch('/^GLOBAL_COVERAGE_MIN\s*:=\s*75\s*$/m');
    expect($makefile)->toMatch('/^DOMAIN_COVERAGE_MIN\s*:=\s*90\s*$/m');
    expect($makefile)->toContain('tools/coverage-gate.php');

    // Y la segunda pasada tiene que poder ejecutarse: el objetivo la invoca, y
    // un fichero que falte —o que .gitignore se lleve por delante, que ya paso
    // con un directorio llamado Coverage/— convierte la puerta en un error de
    // "clase no encontrada" que alguien acabaria comentando.
    expect(is_file(repoFile('backend/tools/coverage-gate.php')))->toBeTrue();
    expect(is_file(repoFile('backend/tools/Quality/DomainCoverageGate.php')))->toBeTrue();
})->group('RNF-M-01');

it('exige el MSI del 80 por ciento sobre el dominio, acotado y con OPcache apagado', function (): void {
    // RQ-10: «pruebas de mutacion sobre el dominio con MSI minimo del 80 %».
    //
    // Tres condiciones, y sin cualquiera de las tres el numero no mide lo que
    // dice:
    //
    // - el umbral, `--min=80`, que es el requisito literal;
    // - acotado a Modules/*/Domain, o el numero mezcla el dominio con los
    //   comandos del repositorio y basta con que estos compensen;
    // - con OPcache apagado, porque el mutante se aplica interceptando el
    //   protocolo file:// y el bytecode cacheado del fichero original lo
    //   anula. Medido: 22 % con OPcache y 100 % sin el, sobre las MISMAS
    //   pruebas y el mismo fichero.
    //
    // Esta prueba no ejecuta la mutacion —eso es `make mutate`, y esta en la
    // etapa ③ de la CI—: comprueba que el umbral y sus dos condiciones siguen
    // escritos donde tienen efecto. Un `--min=80` que alguien baje a 50, o un
    // `--path` que se ensanche a `app/`, dejan la puerta en verde sin comprobar
    // lo que RQ-10 pide.
    $makefile = repoContents('Makefile');

    expect($makefile)->toContain('--min=80');
    expect($makefile)->toContain('--path=$(MUTATE_PATHS)');
    expect($makefile)->toMatch('/^MUTATE_PATHS\s*:=.*DOMAIN_PATHS/m');
    expect($makefile)->toContain('tools/mutation');
    expect(repoContents('backend/tools/mutation/zzz-no-opcache.ini'))
        ->toMatch('/^opcache\.enable_cli\s*=\s*0\s*$/m');

    // Y que la CI lo ejecute de verdad. Un umbral que solo corre en el portatil
    // de quien lo escribio no es una puerta.
    expect(repoContents('.github/workflows/ci.yml'))->toContain('run: make mutate');
})->group('RNF-M-01', 'RQ-10');

it('declara las cinco suites de la piramide de pruebas', function (): void {
    // RQ-14: la cobertura por niveles no la decide quien implementa. La
    // separacion en suites es lo que hace que `test-unit` pueda correr sin base
    // de datos y que la tabla del §9.5 sea aplicable.
    $phpunit = repoContents('backend/phpunit.xml');

    foreach (['Unit', 'Integration', 'Feature', 'Contract', 'Architecture'] as $suite) {
        expect($phpunit)->toContain('name="'.$suite.'"');
    }
})->group('RQ-14');

it('ata cada convencion del stack a una herramienta que la verifica', function (): void {
    // RNF-M-06: "se verifican por herramienta en la CI, no por revision humana".
    // Se comprueba que el fichero de configuracion de cada verificador existe;
    // que ademas se ejecuten lo garantiza `make quality` y la etapa ① de la CI.
    $verifiers = [
        'backend/pint.json' => 'estilo PHP (PSR-12/PER, preset laravel)',
        'backend/phpstan.neon' => 'tipado y complejidad',
        'backend/deptrac.yaml' => 'fronteras entre modulos',
        'backend/rector.php' => 'modernizacion a PHP 8.4',
        '.semgrep/kronoqr-php.yaml' => 'lo que ninguna otra herramienta ve',
        'frontend-kiosk/eslint.config.js' => 'estilo de Vue 3 y TypeScript',
    ];

    $missing = [];

    foreach ($verifiers as $file => $what) {
        if (! file_exists(repoFile($file))) {
            $missing[] = $file.' ('.$what.')';
        }
    }

    expect($missing)->toBe([]);
})->group('RNF-M-06');

it('mantiene los secretos fuera del control de versiones', function (): void {
    // RS-08. El .env lleva el APP_KEY generado y las claves de firma del QR: si
    // entra en el repositorio, la credencial de toda la plantilla es
    // falsificable (regla dura 10).
    expect(repoContents('.gitignore'))->toMatch('/^\.env$/m');
})->group('RS-08');

it('no deja ningun secreto real en el fichero de ejemplo', function (): void {
    // RS-08. .env.example se entrega al cliente (§11.6.1) y es el que se copia
    // en el servidor del hotel: un valor real aqui viaja a cada instalacion.
    $example = repoContents('.env.example');
    $sensitive = [
        'APP_KEY',
        'QR_SIGNING_KEY_CURRENT',
        'BACKUP_ENCRYPTION_KEY',
        'REVERB_APP_SECRET',
        // Tarea 1.12: la clave privada con la que se abren los PIN que el
        // quiosco selle. Un valor real aqui significaria que todas las
        // instalaciones comparten la clave que descifra sus PIN.
        'IDENTITY_PIN_SEALING_SECRET_KEY',
    ];

    $filled = [];

    foreach ($sensitive as $key) {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $example, $m) === 1 && trim($m[1]) !== '') {
            $filled[] = $key;
        }
    }

    expect($filled)->toBe([]);
})->group('RS-08');

it('sirve las cabeceras de seguridad completas', function (): void {
    // RS-09 (§7.2). Permissions-Policy no esta por simetria: sin
    // `camera=(self)` la PWA del quiosco no puede abrir la camara, y el fallo
    // aparecería en la Fase 1 sin causa aparente.
    $headers = repoContents('infra/docker/nginx/snippets/security-headers.conf');

    foreach ([
        'Strict-Transport-Security',
        'Content-Security-Policy',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Permissions-Policy',
    ] as $header) {
        expect($headers)->toContain($header);
    }

    expect($headers)->toContain('camera=(self)');
})->group('RS-09');

it('comprueba el presupuesto de bundle del quiosco en el propio build', function (): void {
    // RNF-P-07 (Anexo A del doc 02): JS critico <= 250 KB gzip, CSS <= 40 KB.
    //
    // La tablet del hotel no es un portatil de desarrollo y la red del centro
    // se cae: cada KB de mas es tiempo de arranque delante de una cola de
    // gente a las 06:00. Por eso el presupuesto se comprueba EN EL BUILD y no
    // en una revision: `npm run build` falla si se excede, asi que no hay
    // forma de publicar un quiosco fuera de presupuesto sin verlo.
    //
    // Esta prueba no mide el bundle —eso lo hace el propio script—: comprueba
    // que el guardian sigue enganchado al build. Un presupuesto que alguien
    // desconecta del `build` deja de existir sin que nadie se entere.
    $script = repoContents('frontend-kiosk/scripts/check-bundle-budget.mjs');

    expect($script)->toContain('250 * KIB');
    expect($script)->toContain('40 * KIB');

    /** @var array{scripts?: array<string, string>} $package */
    $package = json_decode(
        repoContents('frontend-kiosk/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($package['scripts']['build'] ?? '')->toContain('check-bundle-budget');
})->group('RNF-P-07');

it('registra cada decision de arquitectura como ADR versionado', function (): void {
    // RNF-M-04. La comprobacion de verdad la hace `docs:consistency`, que cruza
    // las dos direcciones: cada fila del §4 tiene su fichero y cada fichero su
    // fila. Aqui se ancla el requisito y se comprueba lo que da sentido a esa
    // comprobacion: que los ADR existen como ficheros y no solo como tabla.
    //
    // ADR-026, ADR-027 y ADR-028 vivieron un tiempo solo como fila —autoridad
    // #4 de CLAUDE.md— siendo autoridad #1. Se puede leer el resumen de una
    // decision en una tabla; no su contexto ni sus alternativas descartadas,
    // que es lo que hace falta el dia que alguien quiere revertirla.
    $adrs = glob(repoFile('docs/adr').'/ADR-*.md') ?: [];

    expect(\count($adrs))->toBeGreaterThanOrEqual(30);

    // Y que ninguno sea un esqueleto: un ADR sin consecuencias es un titular.
    $withoutConsequences = array_values(array_filter(
        $adrs,
        static fn (string $file): bool => ! str_contains((string) file_get_contents($file), '## Consecuencias'),
    ));

    expect($withoutConsequences)->toBe([]);
})->group('RNF-M-04');

it('analiza dependencias y codigo en cada integracion', function (): void {
    // RS-10: «ninguna vulnerabilidad critica o alta puede llegar a una version
    // publicada». Tres herramientas, y las tres tienen que estar enganchadas al
    // pipeline: composer audit y npm audit miran lo que traemos de fuera,
    // Semgrep lo que escribimos nosotros.
    //
    // El §10.1 situaba esto en la etapa ⑤, que se ejecuta en pull request. Se
    // adelanta a cada push porque las herramientas ya existian y diferirlo
    // habria dejado un requisito de la Fase 0 sin cumplir por comodidad. Trivy
    // sobre las imagenes sigue en la ⑤: construirlas no cabe en 4 minutos.
    $workflow = repoContents('.github/workflows/ci.yml');

    foreach (['make deps-audit-php', 'make deps-audit-js', 'make sast'] as $step) {
        expect($workflow)->toContain($step);
    }

    // Y el umbral: alto o critico bloquea, moderado no. Una puerta que salta
    // por un aviso informativo se acaba desactivando entera.
    expect(repoContents('Makefile'))->toContain('--audit-level=high');
})->group('RS-10');

it('mantiene el hash señuelo del PIN al mismo coste que produce la instalacion', function (): void {
    // RS-12 y regla dura 17. `HashedEmployeePinVerifier` compara SIEMPRE contra
    // algo —con el hash real cuando hay empleado y no esta bloqueado, con un
    // señuelo en los otros cuatro caminos— para que el tiempo de respuesta no
    // diga si el codigo existe. Esa igualdad de tiempos depende de una sola cosa:
    // que el señuelo tenga **el mismo algoritmo y el mismo coste** que un hash
    // real. Con coste distinto, el rechazo tarda un orden de magnitud mas o menos
    // que el acierto y el oraculo vuelve a existir, medible desde fuera y sin
    // ninguna traza en el log.
    //
    // Hasta ahora el docblock del señuelo AFIRMABA esa coincidencia y nadie la
    // comprobaba: es un literal `$2y$12$…` y coincidia por casualidad.
    //
    // Se comprueban las dos mitades:
    //
    //  1. El señuelo no necesitaria rehash con el coste que produce una
    //     instalacion. `password_needs_rehash` es PHP puro y no necesita el
    //     framework, que es lo que permite que esta comprobacion viva en la
    //     suite de arquitectura.
    //  2. Que ese coste es de verdad el de una instalacion. No se puede leer de
    //     `config('hashing.bcrypt.rounds')` desde aqui: `phpunit.xml` lo fija en
    //     4 para que la suite no tarde minutos, asi que preguntarselo al proceso
    //     de pruebas responderia por el entorno de pruebas y no por el del
    //     cliente. Se comprueba en su lugar que **nada versionado lo cambia**:
    //     sin `config/hashing.php` publicado y sin `BCRYPT_ROUNDS` en el fichero
    //     de ejemplo que se copia en el servidor del hotel, el valor efectivo es
    //     el de Laravel.
    //
    // Si alguien publica `config/hashing.php` o añade `BCRYPT_ROUNDS`, esta
    // prueba falla y obliga a regenerar el señuelo, que es exactamente lo que
    // hay que hacer.
    $installationRounds = 12;

    expect(is_file(repoFile('backend/config/hashing.php')))->toBeFalse(
        'Se ha publicado config/hashing.php: el coste de bcrypt ya no es el de Laravel, '
        .'asi que hay que regenerar DECOY_HASH en HashedEmployeePinVerifier con el coste nuevo.'
    );

    expect(repoContents('.env.example'))->not->toMatch(
        '/^BCRYPT_ROUNDS=/m',
        'El fichero de ejemplo fija BCRYPT_ROUNDS: hay que regenerar DECOY_HASH con ese coste.'
    );

    $decoy = (new ReflectionClass(
        HashedEmployeePinVerifier::class
    ))->getConstant('DECOY_HASH');

    expect($decoy)->toBeString();

    expect(password_needs_rehash(
        is_string($decoy) ? $decoy : '',
        PASSWORD_BCRYPT,
        ['cost' => $installationRounds],
    ))->toBeFalse(
        'El hash señuelo del PIN no coincide con el algoritmo o el coste de la instalacion: '
        .'el rechazo dejaria de tardar lo mismo que el acierto (RS-12, regla dura 17).'
    );
})->group('RS-12', 'RS-03');

it('mantiene el fichero VERSION con un SemVer que el contrato acepte', function (): void {
    // RQ-06. `VERSION` es la fuente de verdad versionada de la version del
    // producto (doc 02 §10.5) y el respaldo de `GET /api/v1/health` cuando el
    // despliegue no fija `APP_VERSION` ni `IMAGE_TAG` con un SemVer.
    //
    // El endpoint promete un SemVer y Spectator lo valida contra el esquema
    // `Health`, asi que un `1.0` o un `v1.0.0` escritos aqui —al etiquetar una
    // version, que es cuando se toca este fichero— dejarian la sonda incumpliendo
    // su propio contrato en la instalacion del cliente. Se comprueba con el mismo
    // patron que usa la resolucion, que es el del contrato.
    //
    // Se lee del repositorio y no de `config('app.version')`: aqui interesa lo
    // que hay ESCRITO en el fichero, no lo que resuelva el entorno de quien
    // ejecuta la suite.
    $version = trim(explode("\n", repoContents('VERSION'), 2)[0]);

    expect(preg_match(DeployedVersion::SEMVER, $version))->toBe(
        1,
        'El fichero VERSION contiene «'.$version.'», que no es un SemVer: '
        .'GET /api/v1/health dejaria de cumplir el esquema Health del contrato.'
    );
})->group('RQ-06');

it('bloquea la integracion si un requisito implementado no tiene prueba', function (): void {
    // RQ-13. Esta es la prueba de la propia trazabilidad: comprueba que el
    // catalogo existe, que no esta vacio y que la etapa que lo ejecuta sigue
    // en el pipeline. Que el comando SEPA fallar lo prueban los sabotajes de
    // tests/Feature/Quality/TraceabilityCommandTest.php.
    $catalog = repoContents('docs/requisitos.yaml');

    expect(substr_count($catalog, '- { id:'))->toBeGreaterThan(100);
    expect($catalog)->toContain('fase: 0');
})->group('RQ-13');

it('restringe el portal del empleado a la red interna en el borde HTTP', function (): void {
    // RF-ID-08, doc 02 §7.5. El PIN de 6 digitos del portal es un espacio
    // pequeño (RS-12); uno de los cuatro controles que lo compensan es que el
    // portal no sea alcanzable desde cualquier IP de internet por defecto.
    //
    // Lo aplica Nginx, no la aplicacion: un geo con PORTAL_INTERNAL_CIDR y un
    // 403 en el borde antes de llegar a PHP-FPM, mismo patron que
    // METRICS_ALLOW_CIDR para /metrics. Esta prueba comprueba la
    // CONFIGURACION -que el candado sigue puesto donde tiene efecto-, no que
    // Nginx lo aplique de verdad en tiempo de ejecucion: eso no es
    // observable sin levantar el contenedor, y `make quality` sobre el
    // Makefile ya construye la imagen y valida la plantilla con `nginx -t`.
    $template = repoContents('infra/docker/nginx/templates/kronoqr.conf.template');

    expect($template)->toContain('${PORTAL_INTERNAL_CIDR}');
    expect($template)->toContain('$kronoqr_portal_allowed');

    // El candado tiene que estar EN la location del portal, no solo declarado
    // en algun sitio del fichero: una geo sin uso no restringe nada.
    if (preg_match('/location \^~ \/api\/v1\/me\/ \{(.*?)\n  \}/s', $template, $match) !== 1) {
        throw new RuntimeException('No se encuentra la location de /api/v1/me/ en la plantilla de Nginx.');
    }

    expect($match[1])->toContain('$kronoqr_portal_allowed');

    // El equivalente en desarrollo: el proxy a Vite del portal tiene el mismo
    // candado, o la restriccion solo existiria en produccion y nadie la
    // comprobaria nunca en local.
    expect(repoContents('infra/docker/nginx/dev-extra/frontends.conf'))
        ->toContain('$kronoqr_portal_allowed');

    // El control fantasma que esta prueba reemplaza: PORTAL_INTERNAL_ONLY
    // existio en .env.example sin que nada lo leyera. Si vuelve a aparecer
    // como variable activa, alguien ha reintroducido un control que no hace
    // nada.
    $example = repoContents('.env.example');

    expect($example)->not->toMatch('/^PORTAL_INTERNAL_ONLY=/m');
    expect($example)->toMatch('/^PORTAL_INTERNAL_CIDR=.+$/m');

    // Y que el parametro de instalacion este documentado para quien despliega
    // en el servidor del cliente, igual que KIOSK_VLAN_CIDR y
    // METRICS_ALLOW_CIDR.
    expect(repoContents('docs/cliente/instalacion.md'))->toContain('PORTAL_INTERNAL_CIDR');
})->group('RF-ID-08');

it('mantiene el alcance de la trazabilidad versionado y no en el entorno', function (): void {
    // RQ-13. `current_phase` decide a que requisitos se les exige prueba. Es
    // ESTADO DEL REPOSITORIO, no configuracion de despliegue, y por eso tiene
    // que valer lo mismo en el portatil y en el runner.
    //
    // Nacio como `env('CURRENT_PHASE', 0)` y la variable no se declaraba en
    // ningun sitio versionado: ni .env.example, ni ci.yml, ni compose. Como
    // backend/.env esta en .gitignore, en la CI el valor era siempre 0. Cerrada
    // la Fase 1, el portatil de quien la cerrara habria exigido prueba a sus
    // requisitos y la CI no. Una puerta que solo salta donde nadie mira.
    $config = repoContents('backend/config/quality.php');

    expect($config)->toMatch(
        '/\'current_phase\'\s*=>\s*\d+\s*,/',
        'quality.current_phase tiene que ser un literal versionado. Si vuelve a leerse del '
        .'entorno, la CI y el portatil dejaran de comprobar lo mismo sin que nadie se entere.'
    );

    expect($config)->not->toMatch(
        '/\'current_phase\'\s*=>[^,]*env\s*\(/',
        'quality.current_phase ha vuelto a leerse de una variable de entorno.'
    );
})->group('RQ-13');
