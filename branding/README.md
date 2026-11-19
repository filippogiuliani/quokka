# Quokka – brand

The family-apps look: a navy rounded square (#1e3a5f), a sand-coloured quokka (#d9a46c) peeking out of a white envelope; the wordmark «qu●kka» (Fredoka, the o is the face).

- `icon.svg` – the app icon (also the source of the PNGs)
- `icon-maskable.svg` – Android: full-bleed background, the quokka inside the safe zone
- `favicon.svg` – the face alone, filled, readable at 16 px

`render.cjs` (Playwright) writes the PNGs and the ICO into `snappymail/v/0.0.0/static/`.
