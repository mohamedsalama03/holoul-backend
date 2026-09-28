---
name: HOLOUL Swagger reference
description: A precise, searchable contract reference using the existing HOLOUL identity and standard Swagger UI.
colors:
  ink: "#0f172a"
  muted: "#52627a"
  line: "#dce3ec"
  soft: "#f6f8fb"
  blue: "#0868d4"
  white: "#fff"
  link: "#145bb6"
  nav-hover: "#eaf0f7"
  nav-selected: "#e5eefb"
  nav-selected-text: "#124d9e"
  button-hover: "#edf3fa"
  button-primary-hover: "#263752"
  get-method: "#2465a8"
  get-surface: "#f2f7fd"
  get-border: "#c7d9ef"
  post-method: "#247354"
  post-surface: "#f0f8f5"
  post-border: "#bbdacc"
  patch-method: "#8b6413"
  patch-surface: "#fff9ed"
  patch-border: "#e5d5ae"
  put-method: "#9a511c"
  put-surface: "#fff5ec"
  put-border: "#e9cbb3"
  delete-method: "#ad3d3d"
  delete-surface: "#fff3f3"
  delete-border: "#e8c6c6"
typography:
  display: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "36px", fontWeight: 650, lineHeight: 1.15, letterSpacing: "-1.1px"}
  headline: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "20px", fontWeight: 700, lineHeight: 1.4, letterSpacing: "-.35px"}
  domain-title: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "17px", fontWeight: 600, lineHeight: 1.5, letterSpacing: "-.2px"}
  body: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "14px", fontWeight: 400, lineHeight: 1.6}
  description: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "13px", fontWeight: 400, lineHeight: 1.8}
  label: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "12px", fontWeight: 600, lineHeight: 1.5}
  path: {fontFamily: 'Consolas, "SFMono-Regular", monospace', fontSize: "12px", fontWeight: 600, lineHeight: 1.55}
  method: {fontFamily: 'Inter, "Segoe UI", Arial, sans-serif', fontSize: "10px", fontWeight: 650, lineHeight: "22px"}
rounded: {control: "6px", method: "4px"}
spacing: {compact: "8px", control-gap: "12px", section: "24px", desktop-gutter: "52px", mobile-gutter: "18px"}
components:
  button-primary: {backgroundColor: "{colors.ink}", textColor: "{colors.white}", typography: "{typography.label}", rounded: "{rounded.control}", padding: "9px 14px"}
  button-secondary: {backgroundColor: "{colors.white}", textColor: "#26364e", typography: "{typography.label}", rounded: "{rounded.control}", padding: "9px 14px"}
  domain-link: {textColor: "#465570", rounded: "{rounded.control}", padding: "8px 12px", width: "100%"}
  domain-link-selected: {backgroundColor: "{colors.nav-selected}", textColor: "{colors.nav-selected-text}"}
  search-input: {backgroundColor: "{colors.white}", textColor: "{colors.ink}", rounded: "{rounded.control}", padding: "10px 13px", height: "44px", width: "100%"}
  method-badge: {textColor: "{colors.white}", typography: "{typography.method}", rounded: "{rounded.method}", padding: "0 5px"}
---

# Design System: HOLOUL Swagger reference

## Overview

**Creative North Star: "A precise, searchable HOLOUL contract reference"**

This records the static `docs/swagger` bundle, using the user-pinned Swagger standard and existing HOLOUL identity. It does not define a new identity or backend/dashboard design system. The direction is code-led: a white reading surface, slate typography, fine rules and compact operation rows. Tokens come from `assets/reference.css`; behavior comes from `assets/reference.js`; page decisions remain in `.impeccable/surfaces/docs-swagger-index-html.md`.

**Key Characteristics:** recognizable Swagger content; restrained HOLOUL blue; flat surfaces; dense technical information with readable expanded prose; local assets without external font requests.

## Colors

**Primary:** `blue` supplies focus outlines; `link` identifies links and clear filters; selected navigation uses the pale/dark selected-blue pair. Preserve the supplied blue mark as an asset rather than recoloring it from a CSS token. **Neutral:** `ink` anchors headings and the JSON action; `muted` supports metadata; `white`, `soft` and `line` separate the reading surface, grouped material and boundaries.

**The Method Color Rule.** GET blue, POST green, PATCH ochre, PUT orange-brown and DELETE red are Swagger semantic exceptions. Each uses its documented dark badge, pale surface and border tokens. Retain textual method labels; do not promote these colors into additional HOLOUL brand accents. Methods without local overrides retain vendor styling. The pale-red load-failure alert is another semantic exception.

## Typography

Display/body text uses installed Inter, then Segoe UI, Arial and sans-serif; no fonts are bundled or fetched. Paths use Consolas, SFMono-Regular and monospace. Operation IDs use Consolas/monospace. The frontmatter records desktop roles. Mobile title size is (31px), with letter spacing (-.9px). Intro text is (15px), reducing to (14px); summaries are (12px); identifiers are (10px); helper text is (11px). Search text increases from (13px) to (16px) on mobile.

**The Reading Measure Rule.** Introductory and expanded operation prose stops at (75ch); release-note measure is (88ch). Tables, schemas and code may use the full operation width and scroll within their containers.

## Layout

Wide screens use a fixed, independently scrollable rail (252px). Main padding is (44px 52px 28px), maximum width (1440px), with a matching rail offset. Title and downloads share a row; facts, candidate note, collapsible integration guide and search precede operations. At most (1150px), the rail narrows to (226px), main padding becomes (32px), the guide becomes one column, and operation IDs/secondary guide-summary text disappear. At most (760px), main padding becomes (25px 18px 24px); a domain selector replaces the rail, the small brand appears, downloads wrap, and metadata uses a full-width version row plus three columns. Summaries and long paths wrap. At least (1800px), main maximum width becomes (1380px), with the greater of the rail width or centered-content offset. Print removes navigation and search/download controls and attempts to keep operations together.

## Elevation & Depth

The shell is flat: surface tones, fine borders and whitespace provide hierarchy. Local overrides remove Swagger operation, button and section-header shadows. Expanded bodies use translucent white (`#ffffffd9`) over method tints. No new shadow scale is introduced; other vendor presentation remains governed by the bundled Swagger stylesheet.

## Shapes

Controls, domain links, operation blocks and schema containers share the `control` radius; method badges use `method`. Dividers are straight and thin. The guide is a rectangular band with top/bottom rules. The provenance hash field retains its local (5px) radius.

## Components

- **Buttons:** dark JSON action, white secondary actions with a fine border (`#cad4e1`), minimum height (38px); mobile downloads reach (42px), with JSON filling remaining width. Hover changes fill without lift. Clear filters is underlined text.
- **Navigation:** transparent rows become tinted on hover; selected rows add blue and weight (600), exposed by `aria-current`. Counts use small tabular numerals. Desktop and mobile share selection state.
- **Search:** visible label, white input, fine border (`#becadb`), muted placeholder (`#627289`). Search combines words across path, method, ID, summary and domains with the selected domain; debounce is (180ms). An announced count, clear action and zero-match recovery panel support filtering. Reset clears both filters and focuses search.
- **Operations and schemas:** method, monospaced path and summary lead each row; IDs appear on wide screens. Keep standard expand/security controls. Summary padding is (8px 10px), reducing to (8px 7px) on mobile; badge minimum width changes from (58px) to (51px). Operations and models begin collapsed, with domain groups expanded. Cool inner model surfaces sit inside a bordered schema container.
- **Disclosures:** native guide and provenance details keep context available on demand. Hash copying reports status and selects the field for manual copying when clipboard access fails.
- **Focus and motion:** local focus outline is blue (3px), offset (3px); a focus-revealed skip link reaches main content. Results use a polite live region and failures an alert. These are implemented affordances, not certification of all vendor states. Smooth document scrolling becomes automatic under reduced-motion preference; transitions/animations are disabled there.

## Do's and Don'ts

- **Do** preserve the supplied mark and its provenance in `THIRD-PARTY-NOTICES.md`; preserve recognizable Swagger semantics and visible focus states.
- **Do** let technical content expand or scroll while constraining prose; keep design changes scoped to this bundle unless broader work is authorized.
- **Don't** introduce a new HOLOUL identity, generated artwork or external fonts to reproduce this surface, or obscure paths/methods with decorative cards.
- **Don't** turn current bundle behavior or an unanswered product choice into a permanent brand rule; retain those decisions in the surface brief.
