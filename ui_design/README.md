# Dragon Fantasy Football UI Kit

A desktop-first UI resource collection for building the fantasy football website.

## Start here
1. Read `DESIGN-SYSTEM.md`.
2. Open `reference-images/00-desktop-page-board.png` for the overall visual target.
3. Review the page-specific instructions in `/layouts`.
4. Open the HTML prototypes in `/examples`.
5. Import CSS from `/styles`.
6. Use `ASSET-CATALOG.md` to manage production graphics.

## Recommended integration into the main project
Copy this entire folder into your repository under one of these locations:

### React / Next.js
`/design-resources/dragon-fantasy-ui-kit/`

Production-approved visual assets should then be copied into:
`/public/assets/dragon-fantasy/`

Application CSS/components can import or translate values from `/styles/tokens.css`.

### Plain HTML
Place this kit beside your source folder:
`/design-resources/dragon-fantasy-ui-kit/`
and copy approved assets to `/assets/dragon-fantasy/`.

## Prompt for the program/UI team
> Use `/design-resources/dragon-fantasy-ui-kit` as the source of truth for visual design. Follow `DESIGN-SYSTEM.md` and `styles/tokens.css` for global design decisions. Use the matching file in `/layouts` and `/examples` for each page. Only use production-approved graphics listed in `ASSET-CATALOG.md`; do not recreate Carroll ISD logos or trademarks.

## What is production-ready vs reference
- CSS tokens, typography hierarchy and desktop layout rules: implementation-ready starting point.
- HTML files: structural prototypes, not a finished application.
- Reference images: visual direction only.
- Generated visual graphics: review/crop/approve before production use.
- Official school trademarks: intentionally excluded.

## Page map
01 Home / Landing
02 Dashboard
03 Matchup
04 Standings
05 Draft Room
06 Roster / My Team
07 League Chat
08 Weekly Recap / News
09 Commissioner Dashboard
10 Champions / Awards
