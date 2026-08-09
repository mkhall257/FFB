# Asset Catalog

This package currently includes generated visual reference boards and source-screen references. The catalog below defines the production asset set the UI team should maintain.

| ID | Suggested filename | Category | Target size | Use |
|---|---|---|---|---|
| H01 | hero-home-stadium-dragon-dark.webp | Hero | 1920×800 | Landing page hero |
| H02 | hero-recap-player-stadium.webp | Hero | 1920×800 | Weekly recap |
| H03 | hero-champions-trophy.webp | Hero | 1920×800 | Awards page |
| B01 | bg-stadium-night-wide.webp | Background | 2560×1440 | Page/section background |
| B02 | bg-green-smoke.webp | Background | 2048×2048 | Atmospheric sections |
| T01 | texture-dragon-scales-dark.webp | Texture | 2048×2048 | Panel/section texture |
| T02 | texture-grunge-charcoal.webp | Texture | 2048×2048 | Subtle page texture |
| T03 | texture-grid-field-dark.webp | Texture | 2048×2048 | Draft/admin detail |
| D01 | dragon-head-original-green.png | Illustration | 1400×1400 alpha | Identity art |
| D02 | dragon-wing-profile-green.png | Illustration | 1800×1400 alpha | Hero support |
| D03 | dragon-silhouette-dark.png | Illustration | 1800×1400 alpha | Background support |
| A01 | trophy-champion-gold.png | Awards | 1200×1600 alpha | Awards hero |
| A02 | badge-league-champion.png | Badge | 800×800 alpha | Award card |
| A03 | badge-mvp.png | Badge | 800×800 alpha | Award card |
| A04 | badge-points-king.png | Badge | 800×800 alpha | Award card |
| O01 | overlay-green-stadium-lights.png | Overlay | 2560×1440 alpha | Hero/recap |
| O02 | overlay-green-smoke.png | Overlay | 2048×2048 alpha | Ambient |
| O03 | overlay-confetti-gold-green.png | Overlay | 2560×1440 alpha | Champion page |

## Naming rule
`<category>-<subject>-<treatment>-<variant>.<ext>`

## Implementation rule
Never reference a generative source filename such as `imagegen.png` in production. Rename approved assets according to this catalog before placing them in the main project.
