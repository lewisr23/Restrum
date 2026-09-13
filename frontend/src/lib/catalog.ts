import { useState, useEffect } from 'react';

import { API } from './config';

// The category tree, loaded once and shared.
//
// Six hundred categories and eight hundred brands is too much to fetch per
// component and far too much to hardcode in the bundle, where it would go
// stale the moment somebody edits the taxonomy on the server. So it is
// fetched once, cached in module scope, and handed to whoever asks.

export type CatalogCategory = {
  slug: string;
  path: string;
  name: string;
  depth: number;
  is_leaf: boolean;
  children: CatalogCategory[];
};

export type Catalog = {
  categories: CatalogCategory[];
  brands: Record<string, string[]>;
};

/** The category as it comes back attached to a listing: just enough to show and link. */
export type ListingCategory = {
  slug: string;
  path: string;
  name: string;
};

export type FacetDefinition = {
  name: string;
  label: string;
  options: string[];
  help: string | null;
};

export type FacetCount = { value: string; count: number };

export type SubcategoryCount = {
  slug: string;
  path: string;
  name: string;
  is_leaf: boolean;
  count: number;
};

export type Facets = {
  subcategories: SubcategoryCount[];
  brands: FacetCount[];
  conditions: FacetCount[];
  locations: FacetCount[];
  attributes: Record<string, FacetCount[]>;
  price: { min: number | null; max: number | null };
  total: number;
};

export type CategoryContext = {
  slug: string;
  path: string;
  name: string;
  depth: number;
  is_leaf: boolean;
  breadcrumbs: { slug: string; name: string }[];
  facet_definitions: FacetDefinition[];
};

// The in-flight or resolved request, not the data. Caching the promise rather
// than the result means two components mounting together share one request
// instead of racing to make two.
let pending: Promise<Catalog> | null = null;

export function loadCatalog(): Promise<Catalog> {
  if (pending === null) {
    pending = fetch(`${API}/api/catalog`, { headers: { Accept: 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error('Could not load the catalog');
        return res.json();
      })
      .catch(err => {
        // Clear the cache on failure, or one dropped connection at startup
        // leaves every future caller holding the same rejected promise for
        // the life of the page.
        pending = null;
        throw err;
      });
  }

  return pending;
}

export function useCatalog() {
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let live = true;

    loadCatalog()
      .then(data => { if (live) setCatalog(data); })
      .catch(() => { if (live) setError('Could not load categories.'); });

    // Guards against setting state on a component that unmounted while the
    // request was in flight, which React warns about and which happens
    // constantly on a page people click through quickly.
    return () => { live = false; };
  }, []);

  return { catalog, loading: catalog === null && error === '', error };
}

/** Depth-first search for a category by slug. */
export function findCategory(categories: CatalogCategory[], slug: string): CatalogCategory | null {
  for (const category of categories) {
    if (category.slug === slug) return category;

    const found = findCategory(category.children, slug);
    if (found) return found;
  }

  return null;
}

/** The chain from department down to the given category, inclusive. */
export function pathTo(categories: CatalogCategory[], slug: string): CatalogCategory[] {
  for (const category of categories) {
    if (category.slug === slug) return [category];

    const rest = pathTo(category.children, slug);
    if (rest.length > 0) return [category, ...rest];
  }

  return [];
}

/** The department slug a category sits in, which is its first path segment. */
export function departmentOf(path: string): string {
  return path.split('/')[0];
}

// The department glyphs used to be emoji, and lived here. They are drawn
// SVG now and live in components/Icon.tsx, because they became markup rather
// than data the moment they stopped being characters in a string.
