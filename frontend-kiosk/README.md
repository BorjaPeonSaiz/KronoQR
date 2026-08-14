# frontend-kiosk

Quiosco de fichaje de KronoQR. Vue 3.5 + TypeScript estricto + Vite 6 + Tailwind 4 (doc 02 §3.3).

## Comandos

```bash
npm install          # en el HOST, no dentro del contenedor (ver mas abajo)
npm run dev          # servidor de desarrollo
npm run type-check   # vue-tsc en modo estricto, 0 errores
npm run lint         # ESLint + Prettier, sin desviaciones
npm run test:unit    # Vitest con cobertura, umbral 70 %
npm run build        # construye y comprueba el presupuesto del Anexo A
npm run api:generate # regenera el cliente HTTP desde docs/api/openapi.yaml
```

## `npm` se ejecuta en el host

El codigo vive en NTFS y Docker lo expone a los contenedores por _bind mount_. Medido en
esta maquina: leer 2.000 ficheros a traves de esa frontera cuesta **15.294 ms frente a 30 ms**
en disco local. Un `node_modules` con miles de ficheros hace inusable el ciclo de desarrollo.
Es el mismo motivo por el que `vendor/` de PHP vive en un volumen nombrado
(`plan implementacion/01-herramientas-y-entorno.md` §B.6).

El servicio `node-kiosk` de Compose sigue sirviendo para levantar Vite dentro de la red de
Docker; instalar las dependencias desde el host y desde el contenedor son alternativas, no
pasos encadenados.
