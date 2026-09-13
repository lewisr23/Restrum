import { useState } from 'react';

import { CategoryContext, Facets, FacetCount } from '../lib/catalog';

export type BrowseFilters = {
  category: string | null;
  search: string;
  brands: string[];
  conditions: string[];
  locations: string[];
  attributes: Record<string, string[]>;
  minPrice: string;
  maxPrice: string;
  availability: 'all' | 'available';
  sort: string;
};

export const EMPTY_FILTERS: BrowseFilters = {
  category: null,
  search: '',
  brands: [],
  conditions: [],
  locations: [],
  attributes: {},
  minPrice: '',
  maxPrice: '',
  availability: 'all',
  sort: 'newest',
};

const CONDITION_LABELS: Record<string, string> = {
  MINT: 'Mint',
  EXCELLENT: 'Excellent',
  GOOD: 'Good',
  FAIR: 'Fair',
};

/** Adds or removes one value from a list of selected values. */
function toggle(values: string[], value: string): string[] {
  return values.includes(value) ? values.filter(v => v !== value) : [...values, value];
}

/**
 * One collapsible block of tickboxes.
 *
 * Long lists collapse to the first eight with a "show all" underneath, and
 * gain a search box past twenty. Brands is the reason: there are eight
 * hundred of them, and a panel that renders every one is a panel nobody
 * scrolls to the bottom of.
 */
function FacetSection({
  title,
  help,
  counts,
  selected,
  onToggle,
  labelFor,
  startOpen = true,
}: {
  title: string;
  help?: string | null;
  counts: FacetCount[];
  selected: string[];
  onToggle: (value: string) => void;
  labelFor?: (value: string) => string;
  startOpen?: boolean;
}) {
  const [open, setOpen] = useState(startOpen);
  const [showAll, setShowAll] = useState(false);
  const [needle, setNeedle] = useState('');

  // A ticked option always stays visible even at zero, or unticking it would
  // mean finding it again in a list it is no longer in.
  const visible = counts.filter(c => c.count > 0 || selected.includes(c.value));

  if (visible.length === 0) return null;

  const searchable = visible.length > 20;
  const matching = needle
    ? visible.filter(c => c.value.toLowerCase().includes(needle.toLowerCase()))
    : visible;
  const shown = showAll || needle ? matching : matching.slice(0, 8);

  return (
    <div className="facet">
      <button
        className="facet__header"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
      >
        <span className="facet__title">
          {title}
          {selected.length > 0 && <span className="facet__badge">{selected.length}</span>}
        </span>
        <span className="facet__chevron">{open ? '−' : '+'}</span>
      </button>

      {open && (
        <div className="facet__body">
          {help && <p className="facet__help">{help}</p>}

          {searchable && (
            <input
              className="facet__search"
              type="text"
              placeholder={`Search ${title.toLowerCase()}`}
              value={needle}
              onChange={e => setNeedle(e.target.value)}
            />
          )}

          {shown.map(option => (
            <label key={option.value} className="facet__option">
              <input
                type="checkbox"
                checked={selected.includes(option.value)}
                onChange={() => onToggle(option.value)}
              />
              <span className="facet__label">{labelFor ? labelFor(option.value) : option.value}</span>
              <span className="facet__count">{option.count}</span>
            </label>
          ))}

          {!showAll && !needle && matching.length > shown.length && (
            <button className="facet__more" onClick={() => setShowAll(true)}>
              Show all {matching.length}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function FilterPanel({
  category,
  facets,
  filters,
  onChange,
  onPickCategory,
}: {
  category: CategoryContext | null;
  facets: Facets | null;
  filters: BrowseFilters;
  onChange: (next: BrowseFilters) => void;
  onPickCategory: (slug: string | null) => void;
}) {
  if (!facets) return null;

  const set = (patch: Partial<BrowseFilters>) => onChange({ ...filters, ...patch });

  const setAttribute = (name: string, value: string) => {
    const current = filters.attributes[name] ?? [];
    const next = toggle(current, value);
    const attributes = { ...filters.attributes };

    if (next.length === 0) {
      delete attributes[name];
    } else {
      attributes[name] = next;
    }

    set({ attributes });
  };

  // Only the subcategories worth offering. A branch with nothing in it is
  // still real and still linkable from the tree, but a filter panel is about
  // narrowing what is actually here.
  const branches = facets.subcategories.filter(s => s.count > 0);

  return (
    <aside className="filter-panel">
      <div className="filter-panel__section">
        <h2 className="filter-panel__heading">Category</h2>

        {category && (
          <div className="filter-panel__crumbs">
            <button className="filter-panel__crumb" onClick={() => onPickCategory(null)}>
              All gear
            </button>
            {category.breadcrumbs.map(crumb => (
              <button
                key={crumb.slug}
                className="filter-panel__crumb"
                onClick={() => onPickCategory(crumb.slug)}
                disabled={crumb.slug === category.slug}
              >
                {crumb.name}
              </button>
            ))}
          </div>
        )}

        {branches.length > 0 ? (
          <div className="filter-panel__branches">
            {branches.map(branch => (
              <button
                key={branch.slug}
                className="filter-panel__branch"
                onClick={() => onPickCategory(branch.slug)}
              >
                <span>{branch.name}</span>
                <span className="facet__count">{branch.count}</span>
              </button>
            ))}
          </div>
        ) : (
          category && (
            <p className="filter-panel__leaf">
              {/* Two different situations that both leave nothing to show,
                  and telling a buyer the wrong one is worse than telling
                  them nothing: a leaf has no subcategories at all, while a
                  department with empty subcategories has plenty and simply
                  has nothing listed in them yet. */}
              {category.is_leaf
                ? 'You are as deep as this branch goes.'
                : 'Nothing listed further down this branch yet.'}
            </p>
          )
        )}
      </div>

      <div className="filter-panel__section">
        <h2 className="filter-panel__heading">Price</h2>
        <div className="price-filter price-filter--stacked">
          <span className="price-filter__symbol">£</span>
          <input
            className="price-filter__input"
            type="number"
            min={0}
            placeholder={facets.price.min === null ? 'Min' : String(Math.floor(facets.price.min))}
            value={filters.minPrice}
            onChange={e => set({ minPrice: e.target.value })}
            aria-label="Minimum price"
          />
          <span className="price-filter__separator">to</span>
          <input
            className="price-filter__input"
            type="number"
            min={0}
            placeholder={facets.price.max === null ? 'Max' : String(Math.ceil(facets.price.max))}
            value={filters.maxPrice}
            onChange={e => set({ maxPrice: e.target.value })}
            aria-label="Maximum price"
          />
        </div>

        <label className="facet__option facet__option--standalone">
          <input
            type="checkbox"
            checked={filters.availability === 'available'}
            onChange={e => set({ availability: e.target.checked ? 'available' : 'all' })}
          />
          <span className="facet__label">Hide sold listings</span>
        </label>
      </div>

      <FacetSection
        title="Brand"
        counts={facets.brands}
        selected={filters.brands}
        onToggle={value => set({ brands: toggle(filters.brands, value) })}
      />

      <FacetSection
        title="Condition"
        counts={facets.conditions}
        selected={filters.conditions}
        onToggle={value => set({ conditions: toggle(filters.conditions, value) })}
        labelFor={value => CONDITION_LABELS[value] ?? value}
      />

      {/* The per-category filters. Rendered in the order the server declared
          them, which is roughly most useful first, and skipped entirely when
          nothing in the results has that attribute set. */}
      {(category?.facet_definitions ?? []).map(definition => (
        <FacetSection
          key={definition.name}
          title={definition.label}
          help={definition.help}
          counts={facets.attributes[definition.name] ?? []}
          selected={filters.attributes[definition.name] ?? []}
          onToggle={value => setAttribute(definition.name, value)}
        />
      ))}

      <FacetSection
        title="Location"
        counts={facets.locations}
        selected={filters.locations}
        onToggle={value => set({ locations: toggle(filters.locations, value) })}
        startOpen={false}
      />
    </aside>
  );
}

export default FilterPanel;
