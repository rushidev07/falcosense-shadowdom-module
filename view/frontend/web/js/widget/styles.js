/**
 * Base CSS shared across every surface the widget renders into — search
 * overlay, search-results takeover, category-page enhancement. One source of
 * truth for the product card, grid, filter panel, and pagination, so the
 * surfaces can't independently drift.
 *
 * Tuned to the Everest (Ahy_Everest2) palette: deep blue #0d2f47, marketplace
 * red #e63232, warm surface #f4efe4, rounded pills. Two host-influenced custom
 * properties remain — --fs-theme-accent and --fs-theme-font, set by
 * theme-sync.js — each with the Everest value baked in as the var() fallback,
 * so the widget looks on-brand even when detection finds nothing.
 */
export const BASE_CSS = `
:host { all: initial; }
* { box-sizing: border-box; }
.fs-card, .fs-state, .fs-toolbar, .fs-filters, .fs-pagination, .fs-shell {
  font-family: var(--fs-theme-font, inherit);
  color: #0d2f47;
}
.fs-shell { background: #efeadd; }
.fs-state { padding: 56px 24px; text-align: center; color: #5b6b76; font-size: 15px; }
.fs-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid #e3ddcf; gap: 12px; }
.fs-toolbar .fs-count { font-weight: 600; color: #5b6b76; font-size: 14px; }
.fs-sort-select { padding: 8px 14px; border: 1px solid #e3ddcf; border-radius: 9999px; font-size: 13px; background: #fff; color: #0d2f47; }
.fs-layout { display: flex; align-items: flex-start; gap: 40px; padding: 0 24px; }
.fs-filters { width: 240px; flex-shrink: 0; display: flex; flex-direction: column; gap: 22px; padding: 24px 0; }
.fs-filter-group { border: none; margin: 0; padding: 0; }
.fs-filter-group legend { font-size: 14px; font-weight: 700; margin-bottom: 10px; padding: 0; }
.fs-filter-option { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 6px 8px; margin: 0 -8px; cursor: pointer; border-radius: 9999px; transition: background-color .15s ease; }
.fs-filter-option:hover { background: #fff; }
.fs-filter-option input[type="checkbox"] { accent-color: var(--fs-theme-accent, #e63232); }
.fs-filter-option .fs-count { color: #94a2ab; margin-left: auto; }
.fs-price-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
.fs-price-row input { width: 76px; padding: 6px 10px; border: 1px solid #e3ddcf; border-radius: 8px; font-size: 13px; background: #fff; }
.fs-price-row button, .fs-clear-filters { border: 1px solid #0d2f47; background: #fff; border-radius: 9999px; padding: 6px 14px; font-size: 12px; font-weight: 600; color: #0d2f47; cursor: pointer; }
.fs-price-row button:hover, .fs-clear-filters:hover { background: #0d2f47; color: #fff; }
.fs-main { flex: 1; min-width: 0; }
.fs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 28px 20px; padding: 24px 0; list-style: none; margin: 0; }
.fs-card { display: flex; flex-direction: column; color: #0d2f47; }
.fs-card-img-wrap { display: block; aspect-ratio: 1; background: #f4efe4; border-radius: 10px; overflow: hidden; margin-bottom: 12px; }
.fs-card-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.fs-card-name { font-size: 14px; font-weight: 600; line-height: 1.35; margin-bottom: 6px; }
.fs-card-price { font-size: 15px; font-weight: 700; }
.fs-price-special { color: var(--fs-theme-accent, #e63232); }
.fs-price-original { text-decoration: line-through; color: #94a2ab; font-weight: 400; margin-left: 8px; font-size: 13px; }
.fs-card-add { margin-top: 10px; padding: 9px 20px; border: 0; border-radius: 9999px; background: var(--fs-theme-accent, #e63232); color: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; align-self: flex-start; }
.fs-card-add:hover { opacity: .92; }
.fs-card-add[disabled] { opacity: .5; cursor: default; }
.fs-pagination { display: flex; justify-content: center; align-items: center; gap: 16px; padding: 20px 0 40px; font-size: 14px; }
.fs-pagination button { border: 1px solid #0d2f47; background: #fff; border-radius: 9999px; padding: 8px 20px; font-weight: 600; color: #0d2f47; cursor: pointer; }
.fs-pagination button:hover:not(:disabled) { background: #0d2f47; color: #fff; }
.fs-pagination button:disabled { opacity: .35; cursor: default; }
.fs-sort-select:focus, .fs-price-row input:focus {
  outline: 2px solid var(--fs-theme-accent, #e63232);
  outline-offset: 1px;
}
`;
