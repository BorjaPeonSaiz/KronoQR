// Dos versiones del quiosco servidas desde el MISMO origen, cambiadas a mitad
// de prueba (tarea 3.1 de la 2.2.1, `kiosk-upgrade.spec.ts`).
//
// POR QUE NO `vite preview`. Una tablet instalada no se entera de una version
// nueva porque cambie de servidor, sino porque el MISMO origen empieza a servir
// otro `sw.js`. `vite preview` sirve un `dist/` fijo; para cambiarlo habria que
// pararlo y arrancar otro en el mismo puerto mientras el navegador sigue
// abierto. Este servidor hace solo lo que hace Nginx con el quiosco
// (`infra/docker/nginx/extra/spa.conf`, `location ^~ /kiosk/`): ficheros del
// build bajo `/kiosk/`, respaldo a `index.html`, las cabeceras de seguridad de
// la version que se esta sirviendo y el `Cache-Control` del mapa
// `$kronoqr_spa_cache` de la plantilla. Lo de `/api/` lo contesta cada prueba
// con `page.route`; lo que llega aqui (la marca, pedida por el service worker)
// recibe un 404, como un servidor sin marca configurada.
//
// SE CONSTRUYE EL BUILD DE PRODUCCION (`vite build`, sin `--mode`): el que se
// instala en la tablet, sin el gancho de `src/sw/testHooks.ts`. La actualizacion
// de estas pruebas es la de verdad: `registration.update()`, instalacion,
// espera, `SKIP_WAITING` y recarga.

import { spawn } from 'node:child_process'
import { existsSync, statSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import type { AddressInfo } from 'node:net'
import { createRequire } from 'node:module'
import { dirname, extname, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { Page } from '@playwright/test'
import { cspDirective } from './securityHeaders'

/** Prefijo bajo el que Nginx publica el quiosco en produccion. */
export const KIOSK_BASE_PATH = '/kiosk/'

const KIOSK_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

const CONTENT_TYPES: Readonly<Record<string, string>> = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.webmanifest': 'application/manifest+json',
  '.json': 'application/json',
  '.woff2': 'font/woff2',
  '.wasm': 'application/wasm',
}

/**
 * Construye el quiosco con la version `version` en `outDir`. Mismo comando que
 * la imagen de Nginx (`KRONOQR_APP_VERSION`, `KRONOQR_BASE=/kiosk/`).
 */
export async function buildKioskRelease(version: string, outDir: string): Promise<void> {
  const viteBin = resolve(
    dirname(createRequire(import.meta.url).resolve('vite/package.json')),
    'bin/vite.js',
  )
  const output: string[] = []
  const exitCode = await new Promise<number | null>((resolveExit, rejectExit) => {
    const child = spawn(
      process.execPath,
      [viteBin, 'build', '--outDir', outDir, '--emptyOutDir', '--logLevel', 'warn'],
      {
        cwd: KIOSK_ROOT,
        env: { ...process.env, KRONOQR_APP_VERSION: version, KRONOQR_BASE: KIOSK_BASE_PATH },
        stdio: ['ignore', 'pipe', 'pipe'],
      },
    )
    child.stdout.on('data', (chunk: Buffer) => output.push(chunk.toString()))
    child.stderr.on('data', (chunk: Buffer) => output.push(chunk.toString()))
    child.on('error', rejectExit)
    child.on('close', resolveExit)
  })
  if (exitCode !== 0) {
    throw new Error(
      `vite build ${version} -> ${outDir} ha fallado (${exitCode}):\n${output.join('')}`,
    )
  }
}

/** Lo que el servidor publica en un momento dado: un build y sus cabeceras. */
export interface KioskRelease {
  readonly distDir: string
  readonly headers: Readonly<Record<string, string>>
}

export interface KioskReleaseServer {
  /** `http://127.0.0.1:<puerto>`: contexto seguro, como exige un service worker. */
  readonly origin: string
  /** Desde la siguiente peticion, se sirve esta version. Es «ejecutar `update.sh`». */
  publish(release: KioskRelease): void
  close(): Promise<void>
}

/** Fichero del build que corresponde a `pathname`, con el `try_files` de Nginx. */
function fileFor(distDir: string, pathname: string): string {
  const relative = decodeURIComponent(pathname.slice(KIOSK_BASE_PATH.length))
  const candidate = resolve(distDir, relative)
  const insideDist = candidate === distDir || candidate.startsWith(`${distDir}${sep}`)
  if (insideDist && existsSync(candidate) && statSync(candidate).isFile()) {
    return candidate
  }
  return resolve(distDir, 'index.html')
}

/** `$kronoqr_spa_cache` de `kronoqr.conf.template`. */
function cacheControlFor(pathname: string): string {
  return pathname.startsWith(`${KIOSK_BASE_PATH}assets/`)
    ? 'public, max-age=31536000, immutable'
    : 'no-cache'
}

/** Arranca en un puerto libre: no choca con el `vite preview` de los demas E2E. */
export async function startKioskReleaseServer(initial: KioskRelease): Promise<KioskReleaseServer> {
  let current = initial

  const server = createServer((request, response) => {
    const pathname = new URL(request.url ?? '/', 'http://127.0.0.1').pathname
    if (!pathname.startsWith(KIOSK_BASE_PATH)) {
      response.writeHead(404, { 'Content-Type': 'application/problem+json' })
      response.end(JSON.stringify({ type: 'about:blank', title: 'Not Found', status: 404 }))
      return
    }
    const release = current
    const file = fileFor(release.distDir, pathname)
    readFile(file).then(
      (body) => {
        response.writeHead(200, {
          ...release.headers,
          'Content-Type': CONTENT_TYPES[extname(file)] ?? 'application/octet-stream',
          'Cache-Control': cacheControlFor(pathname),
        })
        response.end(body)
      },
      () => {
        response.writeHead(500)
        response.end()
      },
    )
  })

  await new Promise<void>((resolveListen) => server.listen(0, '127.0.0.1', resolveListen))
  const { port } = server.address() as AddressInfo

  return {
    origin: `http://127.0.0.1:${port}`,
    publish(release) {
      current = release
    },
    close: () =>
      new Promise<void>((resolveClose) => {
        server.closeAllConnections()
        server.close(() => resolveClose())
      }),
  }
}

/**
 * Las cabeceras de la 2.1.0: las de produccion con la CSP SIN
 * `'wasm-unsafe-eval'` (el estado anterior a 5105c410, PIN-01). Se DERIVAN del
 * snippet real, no se copian: si el snippet cambia, la version antigua de la
 * prueba cambia con el y solo difiere en lo que la 2.1.0 hacia mal.
 */
export function securityHeadersOf210(
  production: Readonly<Record<string, string>>,
): Record<string, string> {
  const csp = production['Content-Security-Policy'] ?? ''
  const withoutWasm = csp
    .split(';')
    .map((part) => part.replace(/\s+'wasm-unsafe-eval'/g, ''))
    .join(';')
  if (cspDirective(withoutWasm, 'script-src')?.includes("'wasm-unsafe-eval'") !== false) {
    throw new Error(`no se ha podido quitar 'wasm-unsafe-eval' de la CSP: ${csp}`)
  }
  return { ...production, 'Content-Security-Policy': withoutWasm }
}

/** La version que declara la tablet en cada latido (`KioskHeartbeatRequest.app_version`). */
export interface UpgradeHeartbeat {
  readonly appVersions: string[]
  /** Desde el siguiente latido, el servidor declara esta `minimum_app_version`. */
  announceMinimumAppVersion(version: string): void
}

/**
 * Latido de un servidor que se actualiza a mitad de prueba. Sustituye al de
 * `stubKioskApiWithPin` (Playwright usa la ruta registrada mas tarde): llamar
 * DESPUES. `serverTime` sigue al reloj de la pagina, para que la tablet no vea
 * un desfase que no tiene nada que ver con lo que se prueba.
 */
export async function stubUpgradeHeartbeat(
  page: Page,
  serverTime: () => string,
): Promise<UpgradeHeartbeat> {
  const appVersions: string[] = []
  let minimumAppVersion: string | null = null

  await page.route('**/api/v1/kiosk/heartbeat', async (route) => {
    const body = route.request().postDataJSON() as { app_version: string }
    appVersions.push(body.app_version)
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        server_time: serverTime(),
        client_errors_accepted: 0,
        service_code_hash: null,
        break_clocking_enabled: false,
        clock_skew_tolerance_seconds: 900,
        ...(minimumAppVersion === null ? {} : { minimum_app_version: minimumAppVersion }),
      }),
    })
  })

  return {
    appVersions,
    announceMinimumAppVersion(version) {
      minimumAppVersion = version
    },
  }
}

/** Un documento que ha cargado la pagina principal: de donde vino y con que CSP. */
export interface LoadedDocument {
  readonly fromServiceWorker: boolean
  readonly csp: string | undefined
}

/**
 * Anota cada documento de la pagina principal, tambien los que carga la propia
 * tablet al aplicar una version (la recarga de `workbox-window`), que ninguna
 * llamada de la prueba devuelve.
 */
export function recordLoadedDocuments(page: Page): LoadedDocument[] {
  const documents: LoadedDocument[] = []
  page.on('response', (response) => {
    if (!response.request().isNavigationRequest() || response.frame() !== page.mainFrame()) return
    documents.push({
      fromServiceWorker: response.fromServiceWorker(),
      csp: response.headers()['content-security-policy'],
    })
  })
  return documents
}
