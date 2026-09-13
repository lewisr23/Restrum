import { useState, useEffect } from 'react';

import { API } from '../lib/config';
import { FacetDefinition } from '../lib/catalog';

type CategoryDetail = {
  slug: string;
  name: string;
  brands: string[];
  attributes: FacetDefinition[];
};

/**
 * The half of the sell form that depends on which category was chosen.
 *
 * Fetched rather than bundled: which questions a category asks lives on the
 * server, next to the validation that enforces the answers, so the form and
 * the rules behind it cannot describe different things.
 *
 * Every field here is optional. A seller who does not know the shell material
 * of a drum kit they are selling should leave it blank rather than guess, and
 * a filter full of guesses is worse than a filter with gaps.
 */
function CategoryFields({
  categorySlug,
  brand,
  attributes,
  onBrandChange,
  onAttributeChange,
}: {
  categorySlug: string | null;
  brand: string;
  attributes: Record<string, string>;
  onBrandChange: (brand: string) => void;
  onAttributeChange: (name: string, value: string) => void;
}) {
  const [detail, setDetail] = useState<CategoryDetail | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!categorySlug) { setDetail(null); return; }

    let live = true;
    setError('');

    fetch(`${API}/api/catalog/categories/${categorySlug}`, { headers: { Accept: 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error('Could not load that category');
        return res.json();
      })
      .then(body => { if (live) setDetail(body); })
      .catch(() => { if (live) setError('Could not load the details for that category.'); });

    return () => { live = false; };
  }, [categorySlug]);

  if (!categorySlug) return null;
  if (error) return <p className="text-error">{error}</p>;
  if (!detail) return <p className="text-muted">Loading category details...</p>;

  return (
    <>
      <div className="field-group">
        <label className="field-label" htmlFor="brand">Brand</label>
        <select
          className="field field--select"
          id="brand"
          value={brand}
          onChange={e => onBrandChange(e.target.value)}
        >
          <option value="">Not stated</option>
          {detail.brands.map(name => (
            <option key={name} value={name}>{name}</option>
          ))}
        </select>
        <p className="field-hint">
          Not listed? Pick "Other". Buyers filter by brand, so it is worth
          getting right.
        </p>
      </div>

      {detail.attributes.length > 0 && (
        <div className="category-fields">
          <h2 className="category-fields__heading">Details buyers filter by</h2>
          <p className="category-fields__lede">
            All optional. Anything you fill in here is something a buyer can
            narrow their search to, so your listing turns up in more of the
            right places. Leave anything you are unsure of blank.
          </p>

          {detail.attributes.map(attribute => (
            <div className="field-group" key={attribute.name}>
              <label className="field-label" htmlFor={`attr-${attribute.name}`}>
                {attribute.label}
              </label>
              <select
                className="field field--select"
                id={`attr-${attribute.name}`}
                value={attributes[attribute.name] ?? ''}
                onChange={e => onAttributeChange(attribute.name, e.target.value)}
              >
                <option value="">Not stated</option>
                {attribute.options.map(option => (
                  <option key={option} value={option}>{option}</option>
                ))}
              </select>
              {attribute.help && <p className="field-hint">{attribute.help}</p>}
            </div>
          ))}
        </div>
      )}
    </>
  );
}

export default CategoryFields;
