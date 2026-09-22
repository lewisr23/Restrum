import { useTheme } from '../context/ThemeContext';
import { SunIcon, MoonIcon } from './Icon';

/**
 * One button, because one button is the whole decision.
 *
 * It shows where pressing it would take you rather than where you are: a sun
 * on the dark theme, a moon on the light one. The alternative, showing the
 * current state, reads as a status light and people press it expecting
 * nothing to happen.
 *
 * There is no "system" position in the interface even though the underlying
 * choice has one. Following the machine is the default and staying on it is
 * a matter of not pressing anything, which is one fewer state to explain for
 * a setting nobody came here to configure.
 */
function ThemeToggle() {
  const { theme, toggle } = useTheme();
  const goingTo = theme === 'dark' ? 'light' : 'dark';

  return (
    <button
      type="button"
      className="theme-toggle"
      onClick={toggle}
      // The accessible name says what it does. A button labelled only
      // "Theme" tells a screen reader user nothing about which way it goes.
      aria-label={`Switch to the ${goingTo} theme`}
      title={`Switch to the ${goingTo} theme`}
    >
      {theme === 'dark' ? <SunIcon size={16} /> : <MoonIcon size={16} />}
    </button>
  );
}

export default ThemeToggle;
