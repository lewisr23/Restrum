"""
Regenerate the favicon, PWA icons, social card and high-res logo exports.

Everything here is drawn from the same geometry as the LogoMark component in
frontend/src/components/Navbar.tsx, so there is one definition of the mark
rather than a hand-exported PNG that quietly drifts from the site. If the
mark changes there, change PICK_PATH and WAVE_PATH here and re-run:

    python scripts/generate-brand-assets.py

The React tab icon that ships with create-react-app was still in place
before this existed, so every tab of a live marketplace showed somebody
else's logo.

Needs Pillow and nothing else. Drawn rather than rasterised from the SVG
because no SVG rasteriser is installed and the mark is four beziers and
five lines, which is less code than pulling one in.
"""

import os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUBLIC = os.path.join(ROOT, 'frontend', 'public')
BRAND = os.path.join(ROOT, 'docs', 'brand')

# Straight out of the design tokens in frontend/src/styles/abstracts/_variables.scss.
ACCENT = (140, 112, 244, 255)      # $color-accent
WAVE = (18, 20, 15, 255)           # the wave cut through the mark
BG = (18, 18, 18, 255)             # $color-bg
TEXT = (242, 242, 242, 255)        # $color-text
MUTED = (154, 154, 154, 255)       # $color-text-muted

VIEWBOX = 44.0

# The plectrum silhouette, as (start, control1, control2, end) in viewBox
# units. Flat shoulders and a real tip: the near-circular earlier version
# read as a generic audio-app blob.
PICK_PATH = [
    ((22, 3.5), (29.5, 3.5), (36.5, 7.0), (37.6, 12.5)),
    ((37.6, 12.5), (38.8, 18.5), (32.5, 32.5), (22, 40.8)),
    ((22, 40.8), (11.5, 32.5), (5.2, 18.5), (6.4, 12.5)),
    ((6.4, 12.5), (7.5, 7.0), (14.5, 3.5), (22, 3.5)),
]

# The wave cut through it: three cubics approximating one and a half sine
# cycles, symmetric about x=22. Replaced a stack of five bars, which is the
# shape every music player uses and was also sitting off-centre.
WAVE_PATH = [
    ((12.4, 21.9), (14.53, 14.7), (16.67, 14.7), (18.8, 21.9)),
    ((18.8, 21.9), (20.93, 29.1), (23.07, 29.1), (25.2, 21.9)),
    ((25.2, 21.9), (27.33, 14.7), (29.47, 14.7), (31.6, 21.9)),
]

WAVE_WIDTH = 2.9

# Everything is drawn this many times larger than it is saved, then shrunk
# with LANCZOS. Pillow has no antialiasing of its own, so without this the
# curve of the pick comes out as visible stair steps at every size.
SUPERSAMPLE = 8


def bezier(p0, c1, c2, p3, steps=64):
    """Points along one cubic curve."""
    out = []
    for i in range(steps + 1):
        t = i / steps
        u = 1 - t
        out.append((
            u * u * u * p0[0] + 3 * u * u * t * c1[0] + 3 * u * t * t * c2[0] + t * t * t * p3[0],
            u * u * u * p0[1] + 3 * u * u * t * c1[1] + 3 * u * t * t * c2[1] + t * t * t * p3[1],
        ))
    return out


def draw_mark(size, background=None, padding=0.0):
    """
    The mark on its own, as a square RGBA image.

    padding is a fraction of the width left empty around it, which is what
    keeps a launcher icon from touching its own edges.
    """
    big = int(size * SUPERSAMPLE)
    img = Image.new('RGBA', (big, big), background or (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    inner = big * (1 - 2 * padding)
    scale = inner / VIEWBOX
    offset = big * padding

    def at(x, y):
        return (offset + x * scale, offset + y * scale)

    outline = []
    for seg in PICK_PATH:
        outline.extend(at(*p) for p in bezier(*seg))
    draw.polygon(outline, fill=ACCENT)

    # The wave, stamped as overlapping discs along the curve rather than
    # drawn as one thick polyline. Pillow's joint handling leaves visible
    # notches on the outside of a bend, which read as a ragged edge, and
    # stamping gives round ends for free.
    radius = WAVE_WIDTH * scale / 2
    for seg in WAVE_PATH:
        for x, y in bezier(*seg, steps=220):
            px, py = at(x, y)
            draw.ellipse([px - radius, py - radius, px + radius, py + radius], fill=WAVE)

    return img.resize((size, size), Image.LANCZOS)


def rounded_backdrop(size, radius_fraction=0.22):
    """The dark tile a launcher or home screen icon sits on."""
    big = size * SUPERSAMPLE
    tile = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    ImageDraw.Draw(tile).rounded_rectangle(
        [0, 0, big - 1, big - 1],
        radius=int(big * radius_fraction),
        fill=BG,
    )
    return tile.resize((size, size), Image.LANCZOS)


def icon_on_tile(size):
    tile = rounded_backdrop(size)
    tile.alpha_composite(draw_mark(size, padding=0.19))
    return tile


def font(path, size):
    return ImageFont.truetype(os.path.join(r'C:\Windows\Fonts', path), size)


def social_card():
    """
    The 1200x630 card a link to restrum.uk unfurls into on LinkedIn, X,
    Facebook, Slack and anywhere else that reads Open Graph tags. Without
    one, a shared link renders as a grey box with a URL under it.

    Set in Segoe UI rather than the site's Space Grotesk, which is a
    webfont and not installed locally. Close enough in feel, and the
    alternative was downloading a font to build an image.
    """
    w, h = 1200, 630
    card = Image.new('RGBA', (w, h), BG)

    # A soft accent glow behind the mark, so the card is not a flat
    # rectangle of near-black in a feed of white ones.
    #
    # Built on its own layer and composited. ImageDraw REPLACES the pixels
    # it touches rather than blending them, so drawing a low-alpha shape
    # straight onto the card punches a translucent hole in it instead, and
    # the first version of this came out as a solid green block.
    glow = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    cx, cy = 210, 315
    for r in range(460, 0, -6):
        a = int(46 * (1 - r / 460) ** 1.7)
        if a:
            gd.ellipse([cx - r, cy - r * 0.92, cx + r, cy + r * 0.92], fill=(140, 112, 244, a))
    card.alpha_composite(glow)

    draw = ImageDraw.Draw(card)
    draw.rectangle([0, h - 10, w, h], fill=ACCENT)

    card.alpha_composite(draw_mark(210), (104, 210))

    title = font('segoeuib.ttf', 100)
    tagline = font('segoeui.ttf', 30)
    small = font('segoeui.ttf', 27)

    x = 372
    # "Re" plain and "strum" in accent, the same split the navbar uses.
    draw.text((x, 196), 'Re', font=title, fill=TEXT)
    draw.text((x + draw.textlength('Re', font=title), 196), 'strum', font=title, fill=ACCENT[:3])

    # Sized to clear the right edge with a margin: at 34px this ran off
    # the card, and a social scraper crops rather than scaling to fit.
    draw.text((x + 5, 334), 'UK marketplace for secondhand instruments and gear',
              font=tagline, fill=TEXT[:3])
    draw.text((x + 5, 386), 'Escrow payments  ·  condition history  ·  verified sellers',
              font=small, fill=MUTED[:3])
    draw.text((x + 5, 434), 'restrum.uk', font=small, fill=ACCENT[:3])

    return card.convert('RGB')


def svg_mark():
    """The mark as a standalone file, for the SVG favicon and anywhere a
    vector is wanted. Colours are literal here: a favicon is rendered
    outside the page, so a CSS custom property resolves to nothing."""
    def d(path):
        head = f'M{path[0][0][0]} {path[0][0][1]}'
        return head + ''.join(
            f' C{c1[0]} {c1[1]} {c2[0]} {c2[1]} {p3[0]} {p3[1]}'
            for _, c1, c2, p3 in path
        )

    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" width="44" height="44">
  <title>Restrum</title>
  <path d="{d(PICK_PATH)} Z" fill="#8c70f4" />
  <path
    d="{d(WAVE_PATH)}"
    stroke="#12140f"
    stroke-width="{WAVE_WIDTH}"
    stroke-linecap="round"
    fill="none"
  />
</svg>
"""


def main():
    os.makedirs(BRAND, exist_ok=True)

    with open(os.path.join(PUBLIC, 'logo.svg'), 'w', encoding='utf-8') as fh:
        fh.write(svg_mark())

    # Multi-size .ico, because Windows and older browsers each pick a
    # different one out of the file and a single 32px image upscales badly.
    draw_mark(256).save(
        os.path.join(PUBLIC, 'favicon.ico'),
        sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)],
    )

    icon_on_tile(192).save(os.path.join(PUBLIC, 'logo192.png'))
    icon_on_tile(512).save(os.path.join(PUBLIC, 'logo512.png'))
    social_card().save(os.path.join(PUBLIC, 'og-image.png'), optimize=True)

    # For uploading by hand: a LinkedIn profile or project wants a file,
    # not a URL.
    draw_mark(1024).save(os.path.join(BRAND, 'restrum-logo-1024.png'))
    icon_on_tile(1024).save(os.path.join(BRAND, 'restrum-logo-1024-on-dark.png'))
    social_card().save(os.path.join(BRAND, 'restrum-social-1200x630.png'), optimize=True)

    print('Wrote:')
    for path in [
        'frontend/public/logo.svg',
        'frontend/public/favicon.ico',
        'frontend/public/logo192.png',
        'frontend/public/logo512.png',
        'frontend/public/og-image.png',
        'docs/brand/restrum-logo-1024.png',
        'docs/brand/restrum-logo-1024-on-dark.png',
        'docs/brand/restrum-social-1200x630.png',
    ]:
        full = os.path.join(ROOT, path.replace('/', os.sep))
        print(f'  {path}  ({os.path.getsize(full):,} bytes)')


if __name__ == '__main__':
    main()
