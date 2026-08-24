/**
 * Base CSS shared across every surface the widget renders into — search
 * overlay, search-results takeover, category-page enhancement. One source of
 * truth for the product card, grid, filter panel, and pagination, so the
 * surfaces can't independently drift the way OVERLAY_CSS and WIDGET_CSS
 * already had (both once defined an identical .fs-card rule, by hand, in two
 * separate files).
 *
 * References two CSS custom properties a host page can influence —
 * --fs-theme-accent and --fs-theme-font, set by theme-sync.js — each with
 * FalcoSense's own default baked in as the var() fallback, so the widget
 * looks correct and on-brand even when nothing was detected or configured.
 */
export const BASE_CSS = `
:host { all: initial; }
* { box-sizing: border-box; }
.fs-card, .fs-state, .fs-toolbar, .fs-filters, .fs-pagination {
  font-family: var(--fs-theme-font, system-ui, -apple-system, sans-serif);
}
.fs-state { padding: 48px 24px; text-align: center; color: #6e6e73; }
.fs-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid #f0f0f0; gap: 12px; }
.fs-sort-select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
.fs-layout { display: flex; align-items: flex-start; gap: 24px; padding: 0 24px; }
.fs-filters { width: 220px; flex-shrink: 0; display: flex; flex-direction: column; gap: 20px; padding: 20px 0; }
.fs-filter-group { border: none; margin: 0; padding: 0; }
.fs-filter-group legend { font-size: 13px; font-weight: 600; margin-bottom: 8px; padding: 0; }
.fs-filter-option { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 3px 0; cursor: pointer; }
.fs-filter-option input[type="checkbox"] { accent-color: var(--fs-theme-accent, #0f7a5f); }
.fs-filter-option .fs-count { color: #999; margin-left: auto; }
.fs-price-row { display: flex; align-items: center; gap: 6px; }
.fs-price-row input { width: 70px; padding: 4px 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
.fs-price-row button, .fs-clear-filters { border: 1px solid #ddd; background: #fff; border-radius: 4px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
.fs-main { flex: 1; min-width: 0; }
.fs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; padding: 24px 0; list-style: none; margin: 0; }
.fs-card { border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; color: #1d1d1f; }
.fs-card-img-wrap { display: block; aspect-ratio: 1; background: #f5f5f7; }
.fs-card-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.fs-card-name { padding: 8px 10px 2px; font-size: 13px; font-weight: 600; }
.fs-card-price { padding: 0 10px 10px; font-size: 13px; }
.fs-price-original { text-decoration: line-through; color: #999; margin-left: 6px; }
.fs-pagination { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 16px 0 32px; font-size: 13px; }
.fs-pagination button { border: 1px solid #ddd; background: #fff; border-radius: 4px; padding: 4px 10px; cursor: pointer; }
.fs-pagination button:disabled { opacity: .4; cursor: default; }
.fs-sort-select:focus, .fs-price-row input:focus {
  outline: 2px solid var(--fs-theme-accent, #0f7a5f);
  outline-offset: 1px;
}
`;
