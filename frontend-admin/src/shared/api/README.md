# Cliente HTTP generado

Aqui vive el cliente de la API, **generado** a partir de `docs/api/openapi.yaml` con
`npm run api:generate`. No se escribe a mano y no se edita: el contrato es la fuente de
verdad de la API (CLAUDE.md, orden de autoridad 2; ADR-013).

Hoy la carpeta esta vacia porque el contrato lo escribe la **tarea 0.6**. `npm run
api:generate` ya existe y lo dice al ejecutarse en lugar de fallar.

El fichero generado sera `schema.d.ts` y esta excluido de Prettier: no tiene sentido
discutir el formato de un fichero que nadie edita.
