import { CatalogCategory, pathTo } from '../lib/catalog';

/**
 * Picks a leaf of the category tree, one level at a time.
 *
 * A cascade of selects rather than one long list, because the list is six
 * hundred entries deep and "Jack to Jack Instrument Cables" is impossible to
 * find by scrolling. Each level narrows the next, and the picker is only
 * satisfied by a leaf: branches exist to be browsed through, not listed in.
 */
function CategoryPicker({
  categories,
  value,
  onChange,
}: {
  categories: CatalogCategory[];
  value: string | null;
  onChange: (slug: string | null) => void;
}) {
  // The chain of categories leading to the current selection. Recomputed
  // from the slug rather than held in state, so the picker cannot drift out
  // of step with the value it is editing.
  const chain = value ? pathTo(categories, value) : [];

  // One select per level that has something to offer: the departments, then
  // the children of each chosen category.
  const levels: { options: CatalogCategory[]; chosen: string }[] = [
    { options: categories, chosen: chain[0]?.slug ?? '' },
  ];

  chain.forEach((category, depth) => {
    if (category.children.length > 0) {
      levels.push({
        options: category.children,
        chosen: chain[depth + 1]?.slug ?? '',
      });
    }
  });

  const pick = (level: number, slug: string) => {
    if (slug === '') {
      // Cleared this level, so the selection is whatever was chosen above it,
      // and nothing if this is the top level.
      onChange(level === 0 ? null : chain[level - 1].slug);

      return;
    }

    onChange(slug);
  };

  const selected = chain[chain.length - 1] ?? null;
  const needsMore = selected !== null && !selected.is_leaf;

  return (
    <div className="category-picker">
      {levels.map((level, index) => (
        <select
          key={index}
          className="field field--select"
          value={level.chosen}
          onChange={e => pick(index, e.target.value)}
          aria-label={index === 0 ? 'Category' : 'Narrower category'}
        >
          <option value="">
            {index === 0 ? 'Choose a category' : 'Choose a subcategory'}
          </option>
          {level.options.map(option => (
            <option key={option.slug} value={option.slug}>
              {option.name}
            </option>
          ))}
        </select>
      ))}

      {needsMore && (
        <p className="category-picker__hint">
          Keep going: "{selected.name}" has more choices underneath it, and a
          listing has to sit in the most specific one.
        </p>
      )}

      {selected && selected.is_leaf && (
        <p className="category-picker__chosen">
          Listing in <strong>{chain.map(c => c.name).join(' › ')}</strong>
        </p>
      )}
    </div>
  );
}

export default CategoryPicker;
