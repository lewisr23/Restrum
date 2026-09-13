import { useState, useEffect, useRef, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

import ListingCard from './ListingCard';
import FilterPanel, { BrowseFilters, EMPTY_FILTERS } from './FilterPanel';
import { API } from '../lib/config';
import { useCatalog, CatalogCategory, CategoryContext, Facets } from '../lib/catalog';
import { CategoryIcon, AllCategoriesIcon } from './Icon';
import { filtersToParams, paramsToFilters, activeFilters, hasActiveFilters } from '../lib/browse';

const SORT_OPTIONS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
];

function Hero({
  search,
  onSearch,
  departments,
  onPickCategory,
  selectedCategory,
}: {
  search: string;
  onSearch: (v: string) => void;
  departments: CatalogCategory[];
  onPickCategory: (slug: string | null) => void;
  selectedCategory: string | null;
}) {
  return (
    <div className="hero">
      <div className="hero__inner">
        <h1 className="hero__title">
          Find your next instrument.{' '}
          <span className="hero__title-accent">Know its story.</span>
        </h1>
        <p className="hero__lede">
          Secondhand gear from across the UK, with real condition history,
          honest price context, and sellers vouched for by the people
          who've actually dealt with them. We recognise that the history of an
          instrument defines how you play. So we built the perfect marketplace
          for that.
        </p>

        <input
          type="text"
          className="hero-search hero__search"
          placeholder="Search gear: Stratocaster, SM58, OP-1..."
          value={search}
          onChange={e => onSearch(e.target.value)}
        />

        <div className="hero__points">
          {['Gear history on every listing', 'Fair price context', 'Sellers vouched for by the community'].map(t => (
            <span key={t} className="hero__point">
              <span className="hero__tick">✓</span> {t}
            </span>
          ))}
        </div>

        {/* Every department, from the catalog rather than a hardcoded five.
            This is the first thing that tells a visitor it is a whole shop and
            not a guitar site with a few extras, so all fourteen are here.
            Chips rather than cards because fourteen cards is a wall: at this
            size the whole shop fits in the space three tiles used to take,
            and it reads as navigation instead of as content.

            Each chip carries its own entrance delay, so the row assembles
            left to right rather than appearing all at once. The subcategory
            names moved to the title attribute: the filter panel lists them
            properly, and they were the reason the cards were so tall. */}
        <div className="dept-rail">
          <button
            className={`dept-chip${selectedCategory === null ? ' dept-chip--active' : ''}`}
            style={{ animationDelay: '0ms' }}
            onClick={() => onPickCategory(null)}
          >
            <span className="dept-chip__icon"><AllCategoriesIcon /></span>
            <span className="dept-chip__name">All gear</span>
          </button>

          {departments.map((department, index) => (
            <button
              key={department.slug}
              className={`dept-chip${selectedCategory === department.slug ? ' dept-chip--active' : ''}`}
              style={{ animationDelay: `${(index + 1) * 45}ms` }}
              onClick={() => onPickCategory(department.slug)}
              title={department.children.slice(0, 4).map(c => c.name).join(', ')}
            >
              <span className="dept-chip__icon"><CategoryIcon slug={department.slug} /></span>
              <span className="dept-chip__name">{department.name}</span>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

function Browse() {
  const [params, setParams] = useSearchParams();
  const { catalog } = useCatalog();
  const gridRef = useRef<HTMLDivElement>(null);

  // The URL is the source of truth for everything except the search box,
  // which needs to lag behind what is typed so that browsing does not fire a
  // request per keystroke.
  const filters = paramsToFilters(params);
  const [searchText, setSearchText] = useState(filters.search);

  const [listings, setListings] = useState<any[]>([]);
  const [facets, setFacets] = useState<Facets | null>(null);
  const [category, setCategory] = useState<CategoryContext | null>(null);
  const [meta, setMeta] = useState<{ total: number; last_page: number } | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const apply = useCallback((next: BrowseFilters) => {
    setPage(1);
    // replace: false, so each change is a step the back button can undo.
    setParams(filtersToParams(next));
  }, [setParams]);

  // Debounced, and only for the text box. Everything else is a click, and a
  // click should take effect immediately.
  useEffect(() => {
    if (searchText === filters.search) return;

    const timer = setTimeout(() => apply({ ...filters, search: searchText }), 350);

    return () => clearTimeout(timer);
  }, [searchText, filters, apply]);

  // Keeps the box in step when the URL changes from somewhere else: the back
  // button, or a category tile that clears the search.
  useEffect(() => {
    setSearchText(filters.search);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.toString()]);

  useEffect(() => {
    const query = filtersToParams(filters);
    if (page > 1) query.set('page', String(page));

    let live = true;
    setLoading(true);

    fetch(`${API}/api/listings?${query}`, { headers: { Accept: 'application/json' } })
      .then(res => {
        if (res.status === 404) throw new Error('That category does not exist.');
        if (!res.ok) throw new Error('Could not load listings.');
        return res.json();
      })
      .then(body => {
        if (!live) return;
        // Laravel's paginated resource collection wraps the array in
        // {data: [...], links, meta}, with our own facets and category
        // context alongside them.
        setListings(body.data);
        setFacets(body.facets);
        setCategory(body.category);
        setMeta(body.meta);
        setError('');
        setLoading(false);
      })
      .catch(err => {
        if (!live) return;
        setError(err.message || 'Could not connect to the server.');
        setLoading(false);
      });

    return () => { live = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.toString(), page]);

  const pickCategory = (slug: string | null) => {
    apply({ ...filters, category: slug, attributes: {} });
    setTimeout(() => gridRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
  };

  const chips = activeFilters(filters);

  return (
    <div>
      <Hero
        search={searchText}
        onSearch={setSearchText}
        departments={catalog?.categories ?? []}
        onPickCategory={pickCategory}
        selectedCategory={filters.category}
      />

      <div ref={gridRef} className="browse browse--faceted">
        <FilterPanel
          category={category}
          facets={facets}
          filters={filters}
          onChange={apply}
          onPickCategory={pickCategory}
        />

        <div className="browse__results">
          <div className="browse__header">
            <h2 className="browse__heading">
              {category ? category.name : 'Latest gear'}
              {!loading && !error && facets && (
                <span className="browse__count">
                  {facets.total} {facets.total === 1 ? 'listing' : 'listings'}
                </span>
              )}
            </h2>

            <select
              className="field field--select browse__sort"
              value={filters.sort}
              onChange={e => apply({ ...filters, sort: e.target.value })}
              aria-label="Sort results"
            >
              {SORT_OPTIONS.map(option => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </div>

          {chips.length > 0 && (
            <div className="browse__chips">
              {chips.map((chip, index) => (
                <button
                  key={`${chip.label}-${index}`}
                  className="active-filter"
                  onClick={() => apply(chip.remove(filters))}
                >
                  {chip.label} <span className="active-filter__x">×</span>
                </button>
              ))}
              {hasActiveFilters(filters) && (
                <button
                  className="browse__clear"
                  onClick={() => apply({ ...EMPTY_FILTERS, category: filters.category, search: filters.search, sort: filters.sort })}
                >
                  Clear filters
                </button>
              )}
            </div>
          )}

          {loading && <p className="text-muted">Loading...</p>}
          {error && <p className="text-error">{error}</p>}

          {!loading && !error && listings.length === 0 && (
            <div className="browse__empty">
              Nothing here yet{category ? ` in ${category.name}` : ''}
              {filters.search ? ` matching “${filters.search}”` : ''}
              {chips.length > 0 ? ' with those filters' : ''}.
            </div>
          )}

          <div className="browse__grid">
            {listings.map(listing => (
              <ListingCard
                key={listing.id}
                id={listing.id}
                title={listing.title}
                price={listing.price}
                location={listing.location}
                category={listing.category}
                status={listing.status}
                imageUrl={listing.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null}
                audioUrls={listing.media?.filter((m: any) => m.media_type === 'AUDIO').map((m: any) => m.url)}
              />
            ))}
          </div>

          {meta && meta.last_page > 1 && (
            <div className="browse__pagination">
              <button
                className="btn-ghost"
                disabled={page <= 1}
                onClick={() => { setPage(page - 1); window.scrollTo({ top: gridRef.current?.offsetTop ?? 0 }); }}
              >
                Previous
              </button>
              <span className="browse__page">Page {page} of {meta.last_page}</span>
              <button
                className="btn-ghost"
                disabled={page >= meta.last_page}
                onClick={() => { setPage(page + 1); window.scrollTo({ top: gridRef.current?.offsetTop ?? 0 }); }}
              >
                Next
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export default Browse;
