# Dragon Fantasy Football — Desktop Design System

## Direction
Original fantasy-football identity inspired by the intensity of Texas Friday-night football. No Carroll ISD logos, trademarks or copied wordmarks are included.

## Typography
Use public web fonts:
- **Oswald 700** — H1 / hero display
- **Oswald 600** — H2 / section headings
- **Barlow Condensed 700–800** — scores, standings numbers, timers and stats
- **Inter 600–700** — labels, buttons, navigation
- **Inter 400–500** — body copy

Google Fonts can serve all three families. For production environments that prohibit remote font loading, self-host properly licensed copies or substitute Arial Narrow + Arial.

## Color roles
- `#16A34A` Primary green: CTAs, live states, active nav, positive performance.
- `#063E2E` Deep green: ambient backgrounds and gradients.
- `#080B0C` Black: overall canvas.
- `#111814` Charcoal: primary panels.
- `#1F2A1F` Slate: secondary controls.
- `#F5F7F6` White: primary text.
- `#9AA49E` Muted: metadata and secondary copy.
- `#D4AF37` Gold: champion/award states only.
- `#D94841` Red: injury, loss, error.
- `#E4A62B` Gold-orange: warning/questionable status.

## Desktop rules
- Desktop-only target: 1280px and above.
- Design at 1440px primary viewport; verify at 1280 and 1920.
- Content max width: 1440px.
- Standard outer padding: 32px.
- Grid: 12 columns; standard gap 20px.
- Sticky global navigation: 72px.
- Do not scale page layouts into mobile cards; a separate mobile product would require a separate layout pass.

## UI principles
1. Dark surfaces, bright data.
2. Green is functional, not decorative everywhere.
3. Scores and timers use condensed typography.
4. Decorative dragon/stadium art belongs in heroes and background zones, not behind dense tables.
5. Gold is scarce and therefore meaningful.
6. One panel language across fantasy, editorial and commissioner experiences.

## Asset treatment
- Hero art: 1920×800 or larger, WebP preferred.
- Standard editorial image: 1600×900.
- Background textures: seamless 2048×2048 when possible.
- Transparent illustration assets: PNG/WebP with alpha.
- Use `object-fit: cover` for hero backgrounds.
- Apply a dark gradient overlay of 55–75% behind text.
