<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * Endurecimiento del borde y de la cadena (bloque 12 de la 2.3.0).
 *
 * Fijan la CONFIGURACION que cierra R4-SC-07/R2-DV-02, R4-SC-09/S3, CFG-1,
 * CFG-2, V2-DV-02, V2-DV-04 y R6-KI-02. Que nginx responda lo que se espera lo
 * comprueba `infra/scripts/nginx-smoke.sh` (job ⑧) sobre la imagen real; aqui se
 * impide que alguien deshaga la configuracion sin que ninguna prueba lo vea.
 */

it('ancla con **/ los patrones de secretos del .dockerignore', function (): void {
    // En un .dockerignore, `.env` solo casa en la RAIZ del contexto: `backend/.env`
    // viajaba en `COPY backend/ ./` (R4-SC-07, R2-DV-02).
    $lineas = array_map(trim(...), file(Repo::file('.dockerignore'), FILE_IGNORE_NEW_LINES) ?: []);

    foreach ([
        '**/.env', '**/.env.*', '**/*.pem', '**/*.key', '**/*.crt',
        '**/auth.json', '**/.npmrc', '**/.deptrac.cache', '**/*.log', '**/coverage/', '**/.phpunit.cache/',
    ] as $patron) {
        expect($lineas)->toContain($patron);
    }

    foreach (['.env', '.env.*', '*.pem', '*.key', '*.crt', '*.log', 'coverage/', '.phpunit.cache/'] as $sinAnclar) {
        expect($lineas)->not->toContain($sinAnclar);
    }
})->group('RS-08', 'RS-10');

it('corre blackbox-exporter sin root y solo en la red de observabilidad', function (): void {
    $compose = Yaml::parse(Repo::contents('infra/compose.prod.yaml'));
    $servicios = is_array($compose) && is_array($compose['services'] ?? null) ? $compose['services'] : [];
    $blackbox = is_array($servicios['blackbox-exporter'] ?? null) ? $servicios['blackbox-exporter'] : [];

    expect($blackbox)->not->toBe([])
        ->and($blackbox['user'] ?? null)->toBe('65534:65534')
        // Fuera de `app`: un exportador comprometido no ve postgres, redis ni reverb (R4-SC-09).
        ->and($blackbox['networks'] ?? null)->toBe(['observability']);
})->group('RS-08', 'RS-10');

it('no publica la API HTTP de Reverb (/apps/) en el borde', function (): void {
    $plantilla = Repo::contents('infra/docker/nginx/templates/kronoqr.conf.template');

    expect(preg_match('/location \^~ \/apps\/ \{(.*?)\n  \}/s', $plantilla, $bloque))->toBe(1);
    $cuerpo = $bloque[1] ?? '';
    expect($cuerpo)->toContain('return 404;')
        ->and($cuerpo)->not->toContain('proxy_pass');

    // El backend publica por la red interna, no por el borde: si esto cambia, /apps/ hace falta.
    expect(Repo::contents('.env.example'))->toContain("REVERB_HOST=reverb\n");
})->group('RS-09');

it('limita connect-src al origen propio y envia COEP credentialless en todo el borde', function (): void {
    $comun = Repo::contents('infra/docker/nginx/snippets/security-headers-common.conf');
    expect($comun)->toContain('add_header Cross-Origin-Embedder-Policy "credentialless" always;');

    foreach (['security-headers.conf', 'security-headers-kiosk.conf'] as $snippet) {
        $conf = Repo::contents('infra/docker/nginx/snippets/'.$snippet);
        expect(preg_match('/Content-Security-Policy "[^"]*?connect-src ([^;"]*)/', $conf, $csp))->toBe(1);
        // Solo el propio origen: ni `wss:`/`ws:` sueltos, ni comodines, ni hosts ajenos (CFG-2).
        expect($csp[1] ?? '')->toBe("'self'");
    }
})->group('RS-09');

it('fija por digest todo FROM y todo COPY --from de una imagen externa de los Dockerfile', function (): void {
    // V2-DV-02 y bloque 12: una etiqueta movil rompe la reproducibilidad y deja entrar una base
    // distinta sin que nadie la revise. Las etapas propias (`FROM base`, `--from=vendor`) no cuentan.
    // Las imagenes de observabilidad de compose.prod.yaml siguen fuera (ADR-053).
    $imagenDeLinea = static function (string $linea, array &$etapas): ?string {
        if (preg_match('/^FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?/i', $linea, $m) === 1) {
            if (isset($m[2])) {
                $etapas[] = $m[2];
            }

            return $m[1];
        }

        return preg_match('/^COPY\s+.*--from=(\S+)/', $linea, $m) === 1 ? $m[1] : null;
    };
    $conDigest = static fn (string $imagen): bool => preg_match('/@sha256:[0-9a-f]{64}$|@\$\{[A-Z_]+\}$/', $imagen) === 1;

    $sinDigest = [];
    $vistas = 0;

    foreach (glob(Repo::file('infra/docker').'/*/Dockerfile') ?: [] as $fichero) {
        $etapas = [];
        foreach (explode("\n", (string) file_get_contents($fichero)) as $numero => $linea) {
            $imagen = $imagenDeLinea(trim($linea), $etapas);
            if ($imagen === null || in_array($imagen, $etapas, true)) {
                continue;
            }
            $vistas++;
            if (! $conDigest($imagen)) {
                $sinDigest[] = basename(dirname($fichero)).'/Dockerfile:'.($numero + 1).' '.$imagen;
            }
        }
    }

    expect($vistas)->toBeGreaterThanOrEqual(7)
        ->and($sinDigest)->toBe([], "Imagenes externas sin digest:\n- ".implode("\n- ", $sinDigest));
})->group('RS-10');

it('declara application/manifest+json para el manifiesto estatico sin sustituir el mapa de tipos', function (): void {
    $plantilla = Repo::contents('infra/docker/nginx/templates/kronoqr.conf.template');

    // A nivel http (fuera de toda location): un `types {}` dentro de una location SUSTITUIRIA
    // los tipos de la imagen y el JS y el CSS saldrian sin tipo.
    expect($plantilla)->toMatch('/^types \{\n  application\/manifest\+json webmanifest;\n\}$/m');
    expect(preg_match_all('/^\s+types \{/m', $plantilla))->toBe(0);
    expect(Repo::contents('infra/docker/nginx/extra/spa.conf'))->not->toMatch('/^\s*types \{/m');
})->group('RS-09');

it('exige en release.yml que el commit etiquetado sea ancestro de main, antes de esperar a la CI', function (): void {
    // V6-DV-6. Vive en `resolver`, la raiz de todos los jobs: si falla, no se espera ni se construye nada.
    $workflow = Repo::contents('.github/workflows/release.yml');

    expect(preg_match('/\n  resolver:\n(.*?)\n  etiquetas-libres:/s', $workflow, $resolver))->toBe(1);
    $job = $resolver[1] ?? '';

    expect($job)->toContain('fetch-depth: 0')
        ->and($job)->toContain('git merge-base --is-ancestor "${commit}" refs/remotes/origin/main')
        // Falla cerrado: sin `origin/main` no se publica.
        ->and($job)->toContain('git rev-parse --verify --quiet refs/remotes/origin/main')
        // El checkout verificado usa la salida del paso, y el SHA se exporta al resto.
        ->and($job)->toContain('ref: ${{ steps.resolver.outputs.tag }}')
        ->and($job)->toContain('sha: ${{ steps.ancestro.outputs.sha }}')
        ->and($job)->toContain('bash .github/scripts/resolve-registry.sh');

    // Los demas jobs hacen checkout de ESE SHA y comprueban que la etiqueta no se ha movido.
    expect($workflow)->not->toContain('ref: ${{ needs.resolver.outputs.tag }}');
    $checkouts = substr_count($workflow, 'ref: ${{ needs.resolver.outputs.sha }}');
    expect($checkouts)->toBeGreaterThanOrEqual(7)
        ->and(substr_count($workflow, 'bash .github/scripts/assert-tag-at-sha.sh'))->toBe($checkouts)
        ->and($workflow)->toContain('VERIFIED_SHA: ${{ needs.resolver.outputs.sha }}');
})->group('RS-10');

it('escanea con Trivy las tres imagenes de entrega tambien en la CI de rama', function (): void {
    // V6-DV-7. La de nginx solo se escaneaba en ⑧ de main y de la etiqueta.
    $ci = Repo::contents('.github/workflows/ci.yml');

    expect($ci)->toContain('make build-ci-images IMAGES="postgres app nginx" BUILDX_CACHE=gha')
        ->and($ci)->toContain('make trivy-image TRIVY_IMAGE_SCANNERS=vuln TRIVY_IMAGES="kronoqr/postgres:ci kronoqr/app:ci kronoqr/nginx:ci" TRIVY_FORMAT=json');
})->group('RS-10');

it('programa una auditoria semanal sobre main de dependencias, repositorio e imagenes publicadas', function (): void {
    // V2-DV-01, R6-DV-04. Un aviso externo se ve el lunes y con destinatario, no al publicar.
    $workflow = Repo::contents('.github/workflows/auditoria-semanal.yml');

    expect($workflow)->toMatch('/schedule:\s+# [^\n]*\n\s+- cron: "[0-9*\/, ]+ 1"/')
        ->and($workflow)->toContain('workflow_dispatch:')
        ->and($workflow)->toContain('make deps-audit-php')
        ->and($workflow)->toContain('make deps-audit-js')
        ->and($workflow)->toContain('make trivy-fs')
        ->and($workflow)->toContain('make trivy-image TRIVY_IMAGES="${REGISTRO}/php:${VERSION} ${REGISTRO}/nginx:${VERSION} ${REGISTRO}/postgres:${VERSION}"')
        // La ultima release, validada, y el registro por el script compartido con release.yml.
        ->and($workflow)->toContain('releases/latest')
        ->and($workflow)->toContain('^v[0-9]+\.[0-9]+\.[0-9]+$')
        ->and($workflow)->toContain('bash .github/scripts/resolve-registry.sh')
        // Las imagenes de terceros de compose.prod.yaml: solo informa.
        ->and($workflow)->toContain('config --images')
        ->and($workflow)->toContain('make trivy-image TRIVY_EXIT_CODE=0 TRIVY_IMAGES=')
        // Solo lectura (las imagenes publicadas son publicas: sin login), sobre main, sin secretos propios.
        ->and($workflow)->toContain("permissions:\n  contents: read")
        ->and($workflow)->not->toContain('docker/login-action')
        ->and($workflow)->not->toContain('packages:')
        ->and(substr_count($workflow, 'ref: main'))->toBeGreaterThanOrEqual(4)
        ->and($workflow)->not->toContain('secrets.')
        ->and($workflow)->not->toContain('contents: write');

    // Y la politica de que bloquea en las ramas esta escrita donde el aviso lleva.
    $runbook = Repo::contents('docs/runbooks/triaje-hallazgos-seguridad.md');
    expect($runbook)->toContain('## 4. Qué bloquea y dónde')
        ->and($runbook)->toContain('auditoria-semanal.yml');
})->group('RS-10');

it('hace bloquear los controles de dependencias e imagenes en las ramas solo si el cambio toca lo que auditan', function (): void {
    // R6-DV-04, politica del runbook de triaje §4.2. main y etiquetas bloquean siempre.
    $ci = Repo::contents('.github/workflows/ci.yml');

    expect(preg_match('/\n  security:\n(.*?)\n  frontend-unit:/s', $ci, $job))->toBe(1);
    $security = $job[1] ?? '';

    expect($security)->toContain('id: gate')
        ->and($security)->toContain('fetch-depth: 0')
        ->and($security)->toContain('git merge-base origin/main HEAD')
        ->and($security)->toContain('refs/heads/main')
        ->and($security)->toContain('refs/tags/*')
        ->and($security)->toContain('composer\.lock|package-lock\.json|images\.lock')
        ->and($security)->toContain('Dockerfile')
        ->and($security)->toContain('(^|/)\.dockerignore$|^infra/docker/')
        ->and($security)->toContain('@sha256:');

    // Sin `| grep -q` bajo pipefail en el paso de decision.
    expect(preg_match('/id: gate\n(.*?)\n      - uses:/s', $security, $gate))->toBe(1);
    expect($gate[1] ?? '')->not->toMatch('/\|\s*grep -q/');

    foreach (['audit_php', 'audit_js', 'trivy_fs', 'trivy_image'] as $paso) {
        // Solo el valor explicito `false` relaja: una salida vacia (la puerta no decidio) bloquea.
        expect($security)->toContain("id: {$paso}\n        continue-on-error: \${{ steps.gate.outputs.bloquea == 'false' }}");
    }
    expect($security)->not->toContain("bloquea != 'true'");

    // Los escaneres que no dependen de avisos externos bloquean SIEMPRE, sin pasar por la puerta.
    foreach (['trivy_fs_static', 'trivy_image_secret'] as $paso) {
        expect(preg_match('/id: '.$paso.'\n(.*?)\n\n/s', $security, $bloque))->toBe(1);
        expect($bloque[1] ?? '')->not->toContain('continue-on-error');
    }
    expect($security)->toContain('TRIVY_FS_SCANNERS=vuln')
        ->and($security)->toContain('TRIVY_FS_SCANNERS=misconfig,secret')
        ->and($security)->toContain('TRIVY_IMAGE_SCANNERS=vuln')
        ->and($security)->toContain('TRIVY_IMAGE_SCANNERS=secret');

    // El resultado queda visible aunque no bloquee.
    expect($security)->toContain('Avisar de los controles que han fallado sin bloquear')
        ->and($security)->toContain("if: always() && steps.gate.outputs.bloquea == 'false'");
})->group('RS-10');

it('lleva el padron y el latido del quiosco a zonas propias con la distincion de la VLAN, y solo ellos (R5-DV-05)', function (): void {
    $plantilla = Repo::contents('infra/docker/nginx/templates/kronoqr.conf.template');

    // Las zonas reutilizan la clave de origen de /scan (geo + map) sin gastar su cupo.
    expect($plantilla)->toContain('limit_req_zone $kronoqr_scan_key_vlan  zone=kiosk_vlan:10m  rate=600r/m;')
        ->and($plantilla)->toContain('limit_req_zone $kronoqr_scan_key_other zone=kiosk_other:10m rate=120r/m;');

    foreach (['roster', 'heartbeat'] as $ruta) {
        expect(preg_match('/location \^~ \/api\/v1\/kiosk\/'.$ruta.' \{(.*?)\n  \}/s', $plantilla, $bloque))->toBe(1);
        $cuerpo = $bloque[1] ?? '';
        expect($cuerpo)->toContain('limit_req zone=kiosk_vlan burst=100 nodelay;')
            ->and($cuerpo)->toContain('limit_req zone=kiosk_other burst=60 nodelay;')
            // No comparten cubo con el fichaje ni con el resto de la API.
            ->and($cuerpo)->not->toContain('zone=scan_')
            ->and($cuerpo)->not->toContain('zone=api');
    }

    // `pair/confirm` lo hace el administrador desde el panel, fuera de la VLAN: sigue en `api`.
    expect($plantilla)->not->toMatch('/location [^\n]*\/api\/v1\/kiosk\/pair/');
})->group('RS-02', 'RNF-P-06');
