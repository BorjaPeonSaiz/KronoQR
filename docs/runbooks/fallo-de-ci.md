# Runbook — la CI está en rojo

**Modo de fallo:** una de las etapas ①, ② o ③ de `.github/workflows/ci.yml`
falla, o la puerta de versión bloquea una etiqueta.

**Destinatario:** quien hizo el *push*. No se escala a nadie más: no hay
impacto en producción y la rama no llega a `main` hasta que está verde.

**Impacto en el fichaje: ninguno.** La CI verifica código que todavía no se ha
desplegado en el servidor de ningún cliente. Si estás aquí a las 06:30 porque
alguien no puede fichar, este no es el runbook: mira
[`README.md`](README.md), tabla de los 20 runbooks de operación.

## Qué comprueba cada etapa y qué significa que falle

| Etapa | Comprueba | Un fallo aquí significa |
|---|---|---|
| ① Lint + Tipos · backend | Pint, PHPStan 9, ShellCheck, `shfmt -i 2 -d`, robustez de los scripts | Estilo, tipado o un script de shell que no cumple el §3.5 |
| ① Lint + Tipos · *frontend* | ESLint y `vue-tsc` de cada frontend | Estilo de Vue, `any`, tipos que no cuadran |
| ② Arquitectura | Deptrac y la suite `Architecture` de Pest | **Se ha roto una frontera**: reglas duras 1 o 2 de `CLAUDE.md` |
| ③ Unitarias + Mutación | Suite `Unit` de Pest y MSI ≥ 80 % sobre `Modules/*/Domain` | Una regla de negocio se comporta distinto de lo que dice su prueba |
| Puerta de versión | `CHANGELOG.md` bien formado y, al etiquetar, con entrada para esa versión | Se iba a publicar una versión sin decirle al cliente qué cambia |

La distinción entre ① y ② es deliberada. Que un `use Illuminate\Support\...`
dentro de `Modules/*/Domain/` rompa la etapa ② y no la ① es lo que permite leer
el fallo sin abrir el log: *se ha roto la arquitectura*, no *falta un espacio*.

## Diagnóstico: reproducir el fallo en tu máquina

Cada paso de la CI es una orden del `Makefile`, así que se reproduce entera en
local. Con el entorno levantado (`make up`):

```bash
make sh-lint      # etapa ①  ShellCheck + shfmt + robustez de los scripts
make php-lint     # etapa ①  Pint + PHPStan nivel 9
make rector       # etapa ①  informativo, nunca bloquea
make deptrac      # etapa ②  fronteras entre capas y módulos
make test-arch    # etapa ②  Pest Arch: reloj del sistema, Carbon, Eloquent
make test-unit    # etapa ③  suite unitaria
make mutate       # etapa ③  mutación sobre el dominio
make changelog-check VERSION=1.2.3   # puerta de versión
```

Para ejecutarlo **tal y como lo hace el runner** —herramientas sobre
`backend/`, sin contenedor— añade `CI=true`. Necesitas PHP 8.4 y
`composer install` en `backend/`:

```bash
make php-lint CI=true
```

## Resolución por síntoma

| Síntoma en el log | Qué hacer |
|---|---|
| `vendor/bin/pint --test` lista ficheros | `make shell` y `vendor/bin/pint` (sin `--test`). Corrige y vuelve a empujar |
| PHPStan: `Method ... has no return type` y similares | Se corrige, no se silencia. Un `@phpstan-ignore` solo vale con su motivo entre paréntesis en el propio comentario; Semgrep verifica que lo lleve |
| Deptrac: `App\Modules\X\Domain\... must not depend on Illuminate\...` | Regla dura 1. El dominio no importa el framework: lo que necesita entra por un puerto de `Application/Port/`. **No se añade a `skip_violations`**: ADR-021 y ADR-025 lo prohíben por escrito |
| Deptrac: `Uncovered` | Un fichero ha caído en `ModuleUnclassified`. Suele ser una carpeta nueva que no está en la estructura del §2: muévela a su capa, no relajes la regla |
| Pest Arch: `llama a now()` | Regla dura 2. Inyecta el puerto `Clock`. Sin eso no se pueden probar DST ni medianoche, que son la mitad del riesgo del dominio |
| `Missing script: lint` en un frontend | El `package.json` de ese frontend debe exponer `lint` (ESLint) y `type-check` (`vue-tsc --noEmit`). Es el contrato que espera la etapa ① |
| `la version X no tiene entrada en CHANGELOG.md` | `bash infra/scripts/changelog.sh generate --release X --write`, revisa el resultado, commitea y vuelve a etiquetar |
| `Presupuesto excedido` (aviso, no error) | La etapa ha tardado más de lo que fija el doc 02 §10.1. No rompe la ejecución, pero se mira: una CI lenta se acaba ignorando |

## Pasos que hoy no comprueban nada, y lo dicen

No son fallos: son huecos declarados. Aparecen como anotación en el resumen de
la ejecución precisamente para que nadie los confunda con verde.

| Paso | Por qué está vacío | Se activa solo cuando |
|---|---|---|
| ① ESLint y `vue-tsc` | Los tres frontends no tienen `package.json` | La tarea **0.5** los cree |
| ③ Mutación | `Modules/*/Domain` no existe; sin dominio no hay mutantes | La tarea **1.1** escriba el dominio. El MSI ≥ 80 % se exige desde la **1.2** |
| ③b Trazabilidad | El comando `qa:traceability` no existe | La tarea **0.7** lo cree. El hueco está marcado en `ci.yml` |

Además, y esto **no** se resuelve solo: las suites `Feature`, `Integration` y
`Contract` **no se ejecutan en ningún *push***, porque su sitio es la etapa ④,
que todavía no existe. Mientras tanto, una regresión en una prueba Feature no
la ve la CI. Se ejecutan en local con `make test`.

## Qué no hacer

- **No desactivar un paso para que pase la CI.** Si una comprobación estorba, o
  está mal planteada y se cambia con su justificación, o el código está mal.
- **No añadir un `baseline` de PHPStan ni `skip_violations` en Deptrac.** El
  umbral del doc 02 §9.2 es 0, y una excepción global convierte la cadena en
  decoración.
- **No subir secretos para "arreglar" una etapa.** Ninguna de las etapas 1–3
  necesita credenciales: no hay base de datos, ni registro de imágenes, ni API
  externa. Si una etapa parece necesitar un secreto, está mal diseñada.
