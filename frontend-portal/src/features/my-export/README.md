# my-export

Descarga del registro propio en CSV o PDF (RF-ID-05, RL-05, art. 20 RGPD). Tarea 1.11; el PDF, PR19.

- `MyExportView.vue` — filtro de rango, eleccion de formato y boton de descarga. El CSV cubre la portabilidad del articulo 20 del RGPD; el PDF, sellado, es lo que una persona presenta ante un tercero. Si el servidor no puede generar el PDF (`503`), se avisa y se ofrece el CSV, que no depende de nada. El fichero se suelta en el acto (`downloadDocument` de `@kronoqr/web-kit/downloadDocument`, ADR-036): no queda vivo en el navegador.
- `export.api.ts` — `GET /api/v1/me/export?format=csv|pdf`, con `requestBlob` de `@kronoqr/web-kit/http` y el `Accept` de cada formato. El nombre del fichero lo manda el servidor en `Content-Disposition`, sin nombre ni codigo de nadie. El `WorkDateRange` del rango pedido es el mismo que declara `../my-records/workdays.api.ts`: las dos pantallas piden el mismo tipo de rango.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
