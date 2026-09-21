import { useLayoutEffect, useRef } from 'react';

/**
 * Publishes an element's height as a CSS custom property on :root.
 *
 * Several things on this site stick to the top of the window, and each one
 * below the first has to know how tall the ones above it are. The navbar is
 * 65px at desktop widths and taller once its padding changes at the md
 * breakpoint, so any stylesheet that writes `top: 65px` is right at one
 * window size and wrong at the others. The browse bar shipped with `top: 0`
 * for exactly that reason and spent its life hidden behind the navbar.
 *
 * Measuring is the only honest answer: the height depends on font loading,
 * wrapping and the breakpoint, none of which SCSS can see. A ResizeObserver
 * costs almost nothing and is correct at every width without a magic number
 * anywhere.
 *
 * useLayoutEffect rather than useEffect so the value is set before the
 * browser paints, or the first frame lays out against a missing variable and
 * everything jumps.
 */
export function usePublishedHeight<T extends HTMLElement>(variable: string) {
  const ref = useRef<T>(null);

  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) return;

    const publish = () => {
      document.documentElement.style.setProperty(
        variable,
        `${Math.round(element.getBoundingClientRect().height)}px`,
      );
    };

    publish();

    const observer = new ResizeObserver(publish);
    observer.observe(element);

    return () => {
      observer.disconnect();

      // Back to nothing on unmount. The browse bar is only on the page while
      // someone is browsing, and leaving its height behind would push the
      // filter panel down by the height of a bar that is no longer there.
      document.documentElement.style.removeProperty(variable);
    };
  }, [variable]);

  return ref;
}
