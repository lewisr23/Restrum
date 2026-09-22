import { ReactNode } from 'react';

// The icon set.
//
// Hand drawn SVG rather than an icon library, for the same reason the hero
// tiles originally used emoji: it is not worth a dependency for twenty
// glyphs. Unlike emoji, these inherit `currentColor`, so a chip's icon turns
// green when the chip does and white when it is hovered, and none of them
// arrive in whatever colour the operating system feels like drawing them.
//
// All of them are stroked, never filled, on a 24 unit grid, with round caps
// and a 1.6 stroke. Keeping that consistent is most of what makes a set look
// like a set. They are drawn to read at 16px, which means detail is the enemy:
// anything finer than a couple of units disappears.

const STROKE = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.6,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
};

// Three of these are string instruments with a round body, which is not a
// failure of imagination: a guitar, a bass and a banjo genuinely do look
// alike at this size. They are told apart by what is on the body, which is
// also how you tell them apart across a room.
const DEPARTMENT_GLYPHS: Record<string, ReactNode> = {
  // Acoustic guitar: body, soundhole, neck, headstock.
  'guitars': (
    <>
      <circle cx="8.5" cy="15.5" r="5.5" />
      <circle cx="8.5" cy="15.5" r="1.8" />
      <path d="M12.4 11.6 18.5 5.5" />
      <path d="M17.6 4.6 20.4 7.4" />
    </>
  ),

  // Bass: no soundhole, a pickup across the body, longer neck.
  'bass-guitars': (
    <>
      <circle cx="8" cy="16" r="5.2" />
      <path d="M6 17.8 9.8 14" />
      <path d="M11.7 12.3 19 5" />
      <path d="M18.2 4.2 20.8 6.8" />
    </>
  ),

  // Drum kit: head, shell, a pair of sticks.
  'drums-percussion': (
    <>
      <ellipse cx="12" cy="10.5" rx="6.5" ry="2.8" />
      <path d="M5.5 10.5v5.2c0 1.6 2.9 2.8 6.5 2.8s6.5-1.2 6.5-2.8v-5.2" />
      <path d="M3.5 4.5 7.8 8" />
      <path d="M20.5 4.5 16.2 8" />
    </>
  ),

  // Keyboard: white keys divided, two black keys sitting between them.
  'keys-synths': (
    <>
      <rect x="2.5" y="7" width="19" height="10" rx="1.5" />
      <path d="M7.3 7v10" />
      <path d="M12 7v10" />
      <path d="M16.7 7v10" />
      <path d="M9.6 7v4.5" />
      <path d="M14.3 7v4.5" />
    </>
  ),

  // Microphone on a stand.
  'studio-recording': (
    <>
      <rect x="9.2" y="2.5" width="5.6" height="10.5" rx="2.8" />
      <path d="M5.8 11.2a6.2 6.2 0 0 0 12.4 0" />
      <path d="M12 17.4v3.1" />
      <path d="M8.8 20.5h6.4" />
    </>
  ),

  // PA cabinet: woofer and tweeter.
  'live-sound-pa': (
    <>
      <rect x="5" y="2.5" width="14" height="19" rx="2" />
      <circle cx="12" cy="14.8" r="3.4" />
      <circle cx="12" cy="7" r="1.5" />
    </>
  ),

  // A bank of faders at different positions, which is what a mixer looks
  // like from across a booth.
  'dj-equipment': (
    <>
      <path d="M6 4v16" />
      <path d="M12 4v16" />
      <path d="M18 4v16" />
      <path d="M4 8.5h4" />
      <path d="M10 14h4" />
      <path d="M16 6.5h4" />
    </>
  ),

  // Hi-fi separate: display and a dial.
  'hi-fi-home-audio': (
    <>
      <rect x="2.5" y="6" width="19" height="12" rx="2" />
      <circle cx="17" cy="12" r="2.2" />
      <path d="M6 10h6" />
      <path d="M6 14h4" />
    </>
  ),

  // A record. The one icon here nobody needs a label for.
  'vinyl-tapes-cds': (
    <>
      <circle cx="12" cy="12" r="9" />
      <circle cx="12" cy="12" r="3.4" />
      <circle cx="12" cy="12" r="0.9" fill="currentColor" stroke="none" />
    </>
  ),

  // Trumpet: tube, valves, flared bell.
  'wind-brass': (
    <>
      <path d="M3 12.5h10.5" />
      <path d="M13.5 8 20.5 5v15l-7-3z" />
      <path d="M6.5 12.5V9" />
      <path d="M10 12.5V9" />
    </>
  ),

  // Violin, told by the bow across it rather than by the body shape.
  'orchestral-strings': (
    <>
      <ellipse cx="9.5" cy="15.5" rx="4.6" ry="5.5" />
      <path d="M12.8 11.3 18.5 5.6" />
      <path d="M17.6 4.7 20.4 7.5" />
      <path d="M3.5 7 21 13.5" />
    </>
  ),

  // Banjo: a drum head with a rim, on a neck.
  'folk-traditional': (
    <>
      <circle cx="8.5" cy="15.5" r="5.5" />
      <circle cx="8.5" cy="15.5" r="3.2" />
      <path d="M12.4 11.6 18.5 5.5" />
      <path d="M17.6 4.6 20.4 7.4" />
    </>
  ),

  // A lead: tip, connector, and cable trailing off.
  'cables-power-accessories': (
    <>
      <path d="M2.5 12h5" />
      <rect x="7.5" y="9.3" width="5.2" height="5.4" rx="1.2" />
      <path d="M12.7 12c2.6 0 2.6 4.5 5.2 4.5s2.6-4.5 2.6-4.5" />
    </>
  ),

  // A page of music.
  'sheet-music-tuition': (
    <>
      <rect x="4" y="2.5" width="16" height="19" rx="2" />
      <path d="M7.5 6.8h9" />
      <path d="M7.5 9.8h9" />
      <circle cx="9.8" cy="17" r="1.9" />
      <path d="M11.7 17v-4.6l4.5 1.1" />
    </>
  ),
};

// Anything filed somewhere this set does not know about.
const FALLBACK_GLYPH = (
  <>
    <circle cx="9" cy="17.5" r="3" />
    <path d="M12 17.5V4l7.5-1.6v4" />
  </>
);

function Svg({ size, children, className }: { size: number; children: ReactNode; className?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      className={className}
      // Decoration beside a text label in every case here, so it is hidden
      // from screen readers rather than read out as a second, worse copy of
      // the name next to it.
      aria-hidden="true"
      focusable="false"
      {...STROKE}
    >
      {children}
    </svg>
  );
}

/**
 * The icon for a department, found either by its slug or by any category
 * path inside it.
 */
export function CategoryIcon({
  slug,
  path,
  size = 16,
  className,
}: {
  slug?: string;
  path?: string | null;
  size?: number;
  className?: string;
}) {
  const department = slug ?? (path ? path.split('/')[0] : undefined);
  const glyph = (department && DEPARTMENT_GLYPHS[department]) || FALLBACK_GLYPH;

  return <Svg size={size} className={className}>{glyph}</Svg>;
}

/** Everything, for the chip that clears the category filter. */
export function AllCategoriesIcon({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <rect x="3.5" y="3.5" width="7.5" height="7.5" rx="1.8" />
      <rect x="13" y="3.5" width="7.5" height="7.5" rx="1.8" />
      <rect x="3.5" y="13" width="7.5" height="7.5" rx="1.8" />
      <rect x="13" y="13" width="7.5" height="7.5" rx="1.8" />
    </Svg>
  );
}

export function PinIcon({ size = 14, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <path d="M12 21.5s7-6 7-11.4a7 7 0 1 0-14 0C5 15.5 12 21.5 12 21.5z" />
      <circle cx="12" cy="10" r="2.6" />
    </Svg>
  );
}

export function LockIcon({ size = 14, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2" />
      <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5" />
    </Svg>
  );
}

/**
 * The two faces of the theme toggle, drawn on the same 24 unit grid as the
 * rest of the set so they sit level with the buttons either side of them.
 */
export function SunIcon({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <circle cx="12" cy="12" r="4.2" />
      <path d="M12 2.6v2.4M12 19v2.4M21.4 12H19M5 12H2.6M18.6 5.4l-1.7 1.7M7.1 16.9l-1.7 1.7M18.6 18.6l-1.7-1.7M7.1 7.1 5.4 5.4" />
    </Svg>
  );
}

export function MoonIcon({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <path d="M20.5 14.2A8.6 8.6 0 0 1 9.8 3.5a8.6 8.6 0 1 0 10.7 10.7z" />
    </Svg>
  );
}
export function BellIcon({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <Svg size={size} className={className}>
      <path d="M18 9.4a6 6 0 1 0-12 0c0 5.1-2 6.6-2 6.6h16s-2-1.5-2-6.6z" />
      <path d="M13.7 19.4a2 2 0 0 1-3.4 0" />
    </Svg>
  );
}
