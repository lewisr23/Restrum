import { createContext, useContext, useEffect, useState, useCallback, ReactNode } from 'react';

/**
 * Light or dark, and who decided.
 *
 * Three states rather than two. "system" is not a third colour scheme, it is
 * the absence of a decision, and it has to be storable: a visitor who has
 * never touched the toggle should follow their laptop when it switches at
 * sunset, and one who has chosen dark should keep dark on a machine set to
 * light. Collapsing that into a boolean loses the difference and makes the
 * site override a preference it was never asked to override.
 */
export type ThemeChoice = 'light' | 'dark' | 'system';

/** What is actually on screen, once "system" has been resolved. */
export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'restrum.theme';

interface ThemeValue {
  choice: ThemeChoice;
  theme: Theme;
  setChoice: (choice: ThemeChoice) => void;
  toggle: () => void;
}

const ThemeContext = createContext<ThemeValue | undefined>(undefined);

/**
 * Whether the machine is asking for light.
 *
 * Guarded because matchMedia does not exist in jsdom by default, and a
 * component test that renders anything inside this provider should not have
 * to stub the browser to do it.
 */
function systemPrefersLight(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-color-scheme: light)').matches;
}

function storedChoice(): ThemeChoice {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);

    return saved === 'light' || saved === 'dark' ? saved : 'system';
  } catch {
    // Private browsing, or site data blocked. Following the system is a
    // perfectly good answer when nothing can be remembered.
    return 'system';
  }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [choice, setChoiceState] = useState<ThemeChoice>(storedChoice);
  const [systemLight, setSystemLight] = useState(systemPrefersLight);

  // Kept in state rather than read at render time so that a laptop switching
  // to its night setting while the tab is open actually repaints the page.
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return;

    const query = window.matchMedia('(prefers-color-scheme: light)');
    const onChange = (event: MediaQueryListEvent) => setSystemLight(event.matches);

    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
  }, []);

  const theme: Theme = choice === 'system' ? (systemLight ? 'light' : 'dark') : choice;

  // The stylesheet does the actual work: base/_tokens.scss redefines the
  // palette under [data-theme]. Setting the attribute here and letting CSS
  // decide what it means is what keeps the colours in one file rather than
  // spread between the stylesheet and the component tree.
  //
  // "system" REMOVES the attribute rather than writing the resolved value,
  // so the media query is back in charge and the page keeps following the
  // machine without React having to notice the change.
  useEffect(() => {
    const root = document.documentElement;

    if (choice === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', choice);
    }
  }, [choice]);

  const setChoice = useCallback((next: ThemeChoice) => {
    setChoiceState(next);

    try {
      if (next === 'system') {
        window.localStorage.removeItem(STORAGE_KEY);
      } else {
        window.localStorage.setItem(STORAGE_KEY, next);
      }
    } catch {
      // Not being able to remember the choice is not a reason to refuse to
      // make it. It applies for this tab and is forgotten on the next visit.
    }
  }, []);

  // Flips to the opposite of what is on screen, which is what a single
  // button means. Landing back on the system's own setting is treated as
  // going back to following it, rather than as pinning the same value: the
  // two look identical today and differ the moment the machine changes.
  const toggle = useCallback(() => {
    const wanted: Theme = theme === 'dark' ? 'light' : 'dark';
    const systemWants: Theme = systemLight ? 'light' : 'dark';

    setChoice(wanted === systemWants ? 'system' : wanted);
  }, [theme, systemLight, setChoice]);

  return (
    <ThemeContext.Provider value={{ choice, theme, setChoice, toggle }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme(): ThemeValue {
  const value = useContext(ThemeContext);

  if (value === undefined) {
    throw new Error('useTheme must be used inside a ThemeProvider.');
  }

  return value;
}
