# Cliente de la API

`schema.d.ts` esta **generado** a partir de `docs/api/openapi.yaml` con
`npm run api:generate`. No se escribe a mano y no se edita: el contrato es la
fuente de verdad de la API (CLAUDE.md, orden de autoridad 2; ADR-013).

- `schema.d.ts` — tipos generados. Excluido de Prettier: nadie edita este fichero.
- `types.ts` — alias cortos de `components['schemas'][...]` para los esquemas que usa el portal (`PortalSession`, `EmployeeWorkDays`, ...). No declara ninguna forma nueva.

**`http.ts` ya no vive aqui.** La puerta de salida base hacia la API —token de sesion en un solo
sitio, errores traducidos a un tipo cerrado (`ApiErrorKind`), descarga de documentos sin dejar
rastro— se movio a `@kronoqr/web-kit/http` (ADR-036): es identica para cualquier SPA de KronoQR y
`frontend-admin` la necesitaba tanto como este portal. Los endpoints concretos (`login.api.ts`,
`workdays.api.ts`, `export.api.ts`) siguen siendo de este portal y consumen
`requestJson`/`requestBlob` importandolos de `@kronoqr/web-kit/http`, no de un fichero local.

Si una pantalla necesita algo que el contrato no da, se pide el cambio en `openapi.yaml`; no se
improvisa aqui.

Regenera los tipos con `npm run api:generate` cada vez que cambie el contrato.
