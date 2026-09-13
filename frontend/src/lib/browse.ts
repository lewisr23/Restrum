import { BrowseFilters, EMPTY_FILTERS } from '../components/FilterPanel';

// Filters live in the URL, not just in React state.
//
// A marketplace filter that cannot be linked to is half a filter: no sending
// someone "the 7 inch northern soul singles under a tenner", no back button
// after drilling three levels down, no reloading the page without losing
// where you were. The same encoding is used for the API request and for the
// address bar, so the two can never describe different things.

export function filtersToParams(filters: BrowseFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.search) params.set('search', filters.search);
  if (filters.category) params.set('category', filters.category);
  if (filters.minPrice) params.set('min_price', filters.minPrice);
  if (filters.maxPrice) params.set('max_price', filters.maxPrice);
  if (filters.availability !== 'all') params.set('availability', filters.availability);
  if (filters.sort !== 'newest') params.set('sort', filters.sort);

  filters.brands.forEach(v => params.append('brands[]', v));
  filters.conditions.forEach(v => params.append('conditions[]', v));
  filters.locations.forEach(v => params.append('locations[]', v));

  Object.entries(filters.attributes).forEach(([name, values]) => {
    values.forEach(v => params.append(`attributes[${name}][]`, v));
  });

  return params;
}

export function paramsToFilters(params: URLSearchParams): BrowseFilters {
  const attributes: Record<string, string[]> = {};

  params.forEach((value, key) => {
    // attributes[vinyl_size][] is the shape PHP parses into a nested array,
    // so it is also the shape read back here rather than something neater
    // that would then need translating on the way out.
    const match = key.match(/^attributes\[([^\]]+)\]\[\]$/);
    if (match) {
      const name = match[1];
      attributes[name] = [...(attributes[name] ?? []), value];
    }
  });

  return {
    ...EMPTY_FILTERS,
    search: params.get('search') ?? '',
    category: params.get('category'),
    minPrice: params.get('min_price') ?? '',
    maxPrice: params.get('max_price') ?? '',
    availability: params.get('availability') === 'available' ? 'available' : 'all',
    sort: params.get('sort') ?? 'newest',
    brands: params.getAll('brands[]'),
    conditions: params.getAll('conditions[]'),
    locations: params.getAll('locations[]'),
    attributes,
  };
}

/** Every filter currently applied, as removable chips. */
export type ActiveFilter = { label: string; remove: (filters: BrowseFilters) => BrowseFilters };

export function activeFilters(filters: BrowseFilters): ActiveFilter[] {
  const chips: ActiveFilter[] = [];

  filters.brands.forEach(brand => chips.push({
    label: brand,
    remove: f => ({ ...f, brands: f.brands.filter(b => b !== brand) }),
  }));

  filters.conditions.forEach(condition => chips.push({
    label: condition.charAt(0) + condition.slice(1).toLowerCase(),
    remove: f => ({ ...f, conditions: f.conditions.filter(c => c !== condition) }),
  }));

  filters.locations.forEach(location => chips.push({
    label: location,
    remove: f => ({ ...f, locations: f.locations.filter(l => l !== location) }),
  }));

  Object.entries(filters.attributes).forEach(([name, values]) => {
    values.forEach(value => chips.push({
      label: value,
      remove: f => {
        const next = { ...f.attributes };
        const remaining = (next[name] ?? []).filter(v => v !== value);
        if (remaining.length === 0) { delete next[name]; } else { next[name] = remaining; }
        return { ...f, attributes: next };
      },
    }));
  });

  if (filters.minPrice || filters.maxPrice) {
    chips.push({
      label: `£${filters.minPrice || '0'} to £${filters.maxPrice || 'any'}`,
      remove: f => ({ ...f, minPrice: '', maxPrice: '' }),
    });
  }

  if (filters.availability === 'available') {
    chips.push({
      label: 'Available only',
      remove: f => ({ ...f, availability: 'all' }),
    });
  }

  return chips;
}

/**
 * Whether anything is narrowing the results, ignoring the category.
 *
 * The category is deliberately excluded: it is where you are, not a filter
 * you applied, so "clear all filters" should not also throw you back to the
 * top of the shop.
 */
export function hasActiveFilters(filters: BrowseFilters): boolean {
  return activeFilters(filters).length > 0;
}
