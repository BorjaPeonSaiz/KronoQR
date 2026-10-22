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

    foreach (['**/.env', '**/.env.*', '**/*.pem', '**/*.key', '**/*.crt'] as $patron) {
        expect($lineas)->toContain($patron);
    }

    foreach (['.env', '.env.*', '*.pem', '*.key', '*.crt'] as $sinAnclar) {
        expect($lineas)->not->toContain($sinAnclar);
    }
})->group('RS-08', 'RS-10');

it('corre blackbox-exporter sin root y solo en la red de observabilidad', function (): void {
    $compose = Yaml::parse(Repo::contents('infra/compose.prod.yaml'));
    $blackbox = $compose['services']['blackbox-exporter'] ?? null;

    expect($blackbox)->toBeArray()
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

it('fija la imagen base de nginx por digest', function (): void {
    // V2-DV-02: php y postgres ya iban fijadas; una etiqueta movil rompe la reproducibilidad.
    $dockerfile = Repo::contents('infra/docker/nginx/Dockerfile');

    expect($dockerfile)->toMatch('/^FROM nginxinc\/nginx-unprivileged:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$/m');
})->group('RS-10');

it('declara application/manifest+json para el manifiesto estatico sin sustituir el mapa de tipos', function (): void {
    $plantilla = Repo::contents('infra/docker/nginx/templates/kronoqr.conf.template');

    // A nivel http (fuera de toda location): un `types {}` dentro de una location SUSTITUIRIA
    // los tipos de la imagen y el JS y el CSS saldrian sin tipo.
    expect($plantilla)->toMatch('/^types \{\n  application\/manifest\+json webmanifest;\n\}$/m');
    expect(preg_match_all('/^\s+types \{/m', $plantilla))->toBe(0);
    expect(Repo::contents('infra/docker/nginx/extra/spa.conf'))->not->toMatch('/^\s*types \{/m');
})->group('RS-09');
