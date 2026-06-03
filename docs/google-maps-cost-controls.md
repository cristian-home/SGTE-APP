# Google Maps — controles de costo

Tras un cobro inesperado ($25 en mayo de 2025, generado solo en localhost por
el ciclo de desarrollo), estos son los controles para que no vuelva a pasar. La
causa fue **montajes repetidos de mapas dinámicos** (cada `<Map>` = 1 "Map
Load" facturado) multiplicados por HMR de Vite + StrictMode + el mini-mapa del
dashboard que se montaba en cada visita.

## Modelo de precios (desde marzo 2025)

Google **eliminó el crédito mensual de $200** y lo reemplazó por **10.000
llamadas gratis al mes _por SKU_**. Las SKU que usa esta app son Essentials e
**independientes** entre sí (cada una tiene su propio tope de 10k gratis/mes):

| SKU | Qué la dispara | Gratis/mes | Precio tras el tope |
|---|---|---|---|
| **Dynamic Maps** (Map Loads, Maps JS) | cada vez que se instancia un `<Map>` (`new google.maps.Map`) | 10.000 | ~$7 / 1.000 |
| **Maps Static** | cada `<img>` de `staticmap` (cada URL distinta) | 10.000 | ~$2 / 1.000 |
| **Geocoding** | `resolvePlace` / `reverseGeocode` | 10.000 | ~$5 / 1.000 |
| **Places Autocomplete** | typeahead de direcciones (facturado **por sesión**, no por tecla) | 10.000 sesiones | ver tabla de Google |

**La meta es mantener cada SKU por debajo de 10k/mes.** Para un despliegue de un
solo cliente con tráfico bajo, eso se logra holgadamente una vez eliminado el
desperdicio de dev (el flag de abajo) y el churn por-render. La SKU cara es
**Dynamic Maps**: evita instanciar mapas que no se ven.

## En el código (ya implementado)

- **Flag `VITE_GOOGLE_MAPS_ENABLED`** (ver `.env.example`). En local ponlo en
  `false`: mapas y geocoding se reemplazan por placeholders y **no se hace
  ninguna petición a Google desde dev** (neutraliza HMR + StrictMode, que fue
  la causa de los $25). Es build-time → reconstruye assets tras cambiarlo.
- Mapas dinámicos **lazy** (`useInViewMount` / `useDeferredMount`: no se montan
  hasta verse) y **sin remounts** por cambio de tema (el `colorScheme` se
  actualiza in-place, no se recrea el mapa). El polling (60s dashboard / 300s
  GPS) refresca props, **no** re-instancia el mapa.
- **Previews estáticos de ruta/ubicación** (detalle de servicio, dialog del
  conductor, detalle de ubicación) usan **Google Static Maps**, pero el `<img>`
  se difiere a **client-only + in-view** (`components/services/static-map-image.tsx`):
  no se emite durante SSR y solo se pide cuando el preview entra en viewport.
  Esto elimina el **doble-fetch** que tenían los usuarios en modo oscuro (antes
  se pedía la URL clara en SSR y la oscura en hidratación = 2 requests). Las
  URLs están memoizadas para no reconstruir una variante theme-aware en cada
  re-render.

## En Google Cloud Console (hazlo manualmente — una sola vez)

1. **Budget alert**: Billing → Budgets & alerts → presupuesto de ~$1 con alerta
   al 50/90/100%. Habría avisado en mayo el primer día.
2. **Key de desarrollo separada**, restringida:
   - Application restriction: *HTTP referrers* → `http://localhost:*`,
     `http://127.0.0.1:*`.
   - API restriction: solo las APIs que usa la app (Maps JavaScript, Geocoding,
     Places, Maps Static).
   - **Cuotas tope** (APIs & Services → Quotas) por SKU. Con el tope gratis de
     10k/mes, fija límites diarios bajos en dev (p.ej. *Map loads/día* ≈ 200)
     para que un loop accidental no agote el cupo ni genere cobro.
3. **Key de prod/staging** restringida por referrer al dominio real
   (`appsgte.com`), separada de la de dev. Considera **alertas de cuota** cerca
   de los 10k/mes por SKU para detectar a tiempo cualquier pico.

## Notas

- **No se cachean ni se proxean imágenes de Google** en almacenamiento propio:
  su ToS lo prohíbe ("static maps must always be requested directly from the
  Maps Static API client-side"). Solo vale la caché HTTP del navegador. Por eso
  las palancas legales son: **menos requests** (diferir/in-view), **URLs
  estables** (reusar caché del navegador) y **no doble-fetch**.
- El reuse del `<Map>` del picker entre aperturas se evaluó y se **descartó**:
  exigía modificar el primitivo `Dialog` compartido en un flujo crítico para un
  ahorro marginal bajo el tope de 10k Map Loads/mes.
- Una migración total de los mapas interactivos a un proveedor libre (OSM)
  eliminaría Google por completo; vive en la rama `feat/osm-alternative-map-provider`
  para una versión futura.
