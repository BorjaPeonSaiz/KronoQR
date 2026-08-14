#!/usr/bin/env node
//
// KronoQR — generacion del cliente HTTP a partir del contrato OpenAPI.
//
// El contrato `docs/api/openapi.yaml` es la fuente de verdad de la API
// (CLAUDE.md, orden de autoridad 2; doc 02 §3.3 y ADR-013): los tipos del
// frontend se GENERAN de el y no se escriben a mano.
//
// Hoy el contrato no existe todavia —lo crea la tarea 0.6—. Este guion no
// falla por ello: lo dice con todas las letras y termina en 0. Un guion que
// revienta con "ENOENT: openapi.yaml" no informa a nadie de que la tarea que
// lo crea aun no se ha hecho.
//
// Codigos de salida:
//   0  cliente generado, o contrato aun inexistente (con aviso explicito)
//   1  el contrato existe pero el generador ha fallado

import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const appDir = path.resolve(fileURLToPath(new URL('..', import.meta.url)))
const appName = path.basename(appDir)
const contractPath = path.resolve(appDir, '..', 'docs', 'api', 'openapi.yaml')
const outputPath = path.join(appDir, 'src', 'shared', 'api', 'schema.d.ts')

const log = (message) => process.stdout.write('[api:generate] ' + message + '\n')

if (!existsSync(contractPath)) {
  log('No existe ' + path.relative(appDir, contractPath).replace(/\\/g, '/') + '.')
  log('El contrato OpenAPI es la fuente de verdad de la API y lo escribe la tarea 0.6.')
  log(
    'Cuando exista, este guion escribira ' + path.relative(appDir, outputPath).replace(/\\/g, '/'),
  )
  log('con openapi-typescript. Ahora mismo NO se ha generado nada: no es un fallo.')
  process.exit(0)
}

mkdirSync(path.dirname(outputPath), { recursive: true })
log('Generando los tipos de ' + appName + ' desde el contrato.')

const result = spawnSync('npx', ['openapi-typescript', contractPath, '--output', outputPath], {
  cwd: appDir,
  stdio: 'inherit',
  shell: process.platform === 'win32',
})

if (result.status !== 0) {
  log('openapi-typescript ha fallado. Que hacer: valida el contrato con `npx @redocly/cli lint`')
  log('y vuelve a ejecutar `npm run api:generate`.')
  process.exit(1)
}

log('Tipos escritos en ' + path.relative(appDir, outputPath).replace(/\\/g, '/'))
