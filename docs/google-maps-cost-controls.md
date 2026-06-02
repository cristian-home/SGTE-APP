# Google Maps — controles de costo

Tras un cobro inesperado ($25 en mayo, generado solo en localhost por el ciclo
de desarrollo), estos son los controles para que no vuelva a pasar. La causa
fue **montajes repetidos de mapas dinámicos** (cada `<Map>` = 1 "Map Load"
facturado) multiplicados por HMR de Vite + StrictMode + el mini-mapa del
dashboard que se montaba en cada visita.

## En el código (ya implementado)

- **Flag `VITE_GOOGLE_MAPS_ENABLED`** (ver `.env.example`). En local ponlo en
  `false`: los mapas y el geocoding se reemplazan por placeholders y **no se
  hace ninguna petición a Google desde dev**. Reconstruye assets tras cambiarlo.
- Mapas dinámicos **lazy** (no se montan hasta verse/click) y sin remounts por
  cambio de tema.
- Previews de ruta/ubicación **no usan Google** (se dibujan con MapLibre/OSM
  desde la geometría ya persistida).

## En Google Cloud Console (hazlo manualmente — una sola vez)

1. **Budget alert**: Billing → Budgets & alerts → crear presupuesto de ~$1 con
   alerta al 50/90/100%. Esto habría avisado en mayo el primer día.
2. **Key de desarrollo separada**, restringida:
   - Application restriction: *HTTP referrers* → `http://localhost:*`,
     `http://127.0.0.1:*`.
   - API restriction: solo las APIs que usa la app (Maps JavaScript, Geocoding,
     Places, Static Maps si aplica).
   - **Cuotas tope** (APIs & Services → Quotas) por SKU, p.ej. *Map loads/día*
     bajo (200) — así un loop accidental en dev no puede dispararse.
3. **Key de prod/staging** restringida por referrer al dominio real
   (`appsgte.com`), separada de la de dev.

## Notas

- No se cachean imágenes de mapas de Google en almacenamiento propio: su ToS lo
  prohíbe. Por eso los previews se migraron a render propio.
- El SKU caro es **Dynamic Maps** (~$7/1000 Map Loads); evita montar mapas que
  no se ven.
