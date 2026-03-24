# FRONTEND_EMBED_UI.md

## Rendering structure
The embed frontend remains a lightweight vanilla JS implementation in `embed/embed.js` with Shadow DOM style isolation.

Core structure:
1. Resolve script params + API base.
2. Load form config from `/api/get_form.php` (cached in sessionStorage).
3. Merge UI config (query overrides + server defaults).
4. Render one of two modes:
   - inline card
   - floating trigger + panel
5. Submit to official endpoint `/api/submit.php`.

## Inline mode behavior
Inline mode renders a clean card suitable for:
- product detail sections
- landing/contact blocks
- SEO pages

Characteristics:
- title + subtitle area
- responsive field grid
- explicit labels and required indicators
- balanced textarea/select/input styling
- clear primary submit button
- helper text area (e.g., WhatsApp note)
- success/error state box below action area

## Floating mode behavior
Floating mode renders:
- fixed trigger button (left/right configurable)
- subtle breathing animation and hover feedback
- popup panel with close button
- backdrop overlay
- ESC/overlay/click close behaviors

Mobile adaptation:
- panel becomes a bottom-sheet style surface (full width)
- trigger remains reachable
- larger tap targets and spacing

## Responsive strategy
The embed uses CSS media queries (no heavy framework):
- desktop: 2-column field grid where applicable
- tablet/mobile: single-column inputs
- floating panel width constrained on desktop, full-width sheet on small screens
- typography and paddings tuned for readability and touch interaction

## Animation approach
Animations are intentionally lightweight:
- trigger breathing + hover lift
- panel open/close transform+opacity transition
- minimal overlay fade

No heavy animation libraries are used.

## Interaction states
Handled states include:
- idle
- focus-visible (inputs/buttons)
- hover
- submitting (button disabled + text)
- success message
- validation/error message

Duplicate submit is prevented while request is in-flight.

## Field compatibility and submit contract
- Builtin fields (`name`, `tel`, `email`, `message`) are mapped by `field_key` first.
- Custom fields are emitted by defined field keys from loaded form definitions.
- Payload still includes tracking context and legacy `phone` mirror for compatibility.
- Official submit endpoint remains `/api/submit.php` with `X-API-KEY`.

## Config support
Current frontend supports available/override-able UI settings:
- `title`
- `subtitle`
- `button_text`
- `success_message`
- `helper_text` / `whatsapp_text`
- `theme_color`
- `floating_position`

Sources:
1. query params on embed script (highest priority)
2. server UI block when available
3. built-in defaults

## Known safe gap
Some admin-side UI config fields (title/subtitle/button/theme/etc.) are not fully persisted in DB yet.
Embed now supports them safely via query params and server-default merge without breaking existing behavior.
