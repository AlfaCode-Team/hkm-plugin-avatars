# Changelog

All notable changes to `hkm-plugin-avatars` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: a deterministic initials avatar as SVG, plus a guarded fetch
  for a remote avatar URL.** Solves `avatar.generation`. Needs no database, no
  GD or Imagick, no font file and no other module — `http.client` is optional.

- **`Initials::of()` is grapheme-aware, and the reasons are measured.**
  `mb_substr()` cuts at a CODE POINT, and a user-perceived character is often
  several. On PHP 8.5 / ICU 79: for "école" with a decomposed é (e + U+0301),
  `mb_substr($s, 0, 1)` returns just `0x65` — the letter with its accent
  dropped — while `grapheme_substr` keeps both bytes. A family emoji is 5 code
  points and 1 grapheme, so `mb_substr` on it emits HALF A ZWJ SEQUENCE: a lone
  emoji followed by a dangling joiner, which renders as a broken glyph. There is
  a `\X`-based UTF-8 fallback for a build with neither `ext-intl` nor the
  Symfony polyfill; it keeps combining marks attached and does not understand
  ZWJ sequences, which is stated rather than hidden.

- **First and LAST word, never the first two.** "José María García" gives `JG`,
  not `JM` — taking the first two yields the given name and the MIDDLE name,
  which is not how anyone abbreviates a name. A single word gives ONE letter:
  "Cher" is `C`, never `CH`, because two letters from one word read as the
  initials of two names and a mononym would appear to have a surname. Which also
  handles scripts without spaces: 山田太郎 gives 山.

- **A name with no letters gives nothing**, and the avatar is a plain coloured
  tile. Not a `'?'`: that is a character the user never typed appearing where
  their name should be. An empty tile reads as "no name", which is what is true.

- **`ß` stays one glyph.** `mb_strtoupper('ß')` is `'SS'` — two characters from
  one — so the result is re-cut to a single grapheme rather than widening the
  avatar's text by a letter.

- **The colour is SHA-256 of the case-folded, whitespace-collapsed name.** Never
  `rand()`, `mt_rand()` or `uniqid()`: an avatar that changes colour between two
  page loads reads as a different person. `crc32` is stable but clusters badly on
  short similar strings — exactly the input here. Case folding is
  `mb_strtolower` and not `strtolower`, so "Égypte" and "égypte" are one person;
  without the whitespace collapse, an avatar would change colour the day a
  profile form trimmed a trailing space.

- **The ink is chosen by WCAG 2.1 relative luminance, not fixed to white.** The
  usual mistake is white text on a fixed palette, which fails the moment somebody
  adds a pale colour — initials in white on light yellow are unreadable rather
  than merely ugly. There is a test asserting every one of the sixteen palette
  entries clears 4.5:1 with its computed ink, so a palette edit that breaks
  accessibility fails the suite instead of shipping. The luminance LINEARISES
  each channel before weighting, which is the step people skip: `#808080` is
  halfway in sRGB and about 21.6% in luminance, so a naive `128/255` is wrong by
  more than a factor of two.

- **SVG, with the security consequence handled rather than ignored.** SVG needs
  no rasteriser, no font and no licence, and is ~450 bytes. It is also a
  SCRIPTABLE DOCUMENT: an SVG served from your own origin can carry `<script>`
  and run with your origin's privileges, and the text here comes from a person's
  name. Two independent defences — `Initials` strips everything that is not a
  letter or a digit, and the text is escaped on the way in anyway — plus
  `headers()`, which returns the `Content-Type`, a `default-src 'none'` CSP and
  `nosniff` the document must be served with, and `toDataUri()`, which is safest
  of all because a browser will not run script in an SVG loaded through `<img>`.

- **`dy=".35em"` rather than `dominant-baseline="central"`.** Both centre text in
  a browser; `dominant-baseline` is unsupported or mishandled by librsvg, which
  is what ImageMagick and most server-side SVG-to-PNG converters use — so an
  avatar centred in Chrome would sit high when rasterised for an email.

- **The remote fetch has NO ROUTE, because Appwrite's equivalent is an open
  proxy.** `GET /avatars/image?url=…` is an unauthenticated endpoint that fetches
  an arbitrary URL and returns the bytes: anyone can use the server to reach what
  it can reach and they cannot — a metadata service, an internal admin panel —
  and read the answer out of the response. It is also a free bandwidth
  amplifier. Here the capability is a service method and the application decides
  who may call it.

- **Every outbound default is overridden.** `block_private => true`, because the
  client allows private ranges by default for first-party gateways and that
  default lets a user point an avatar at
  `http://169.254.169.254/latest/meta-data/` and read IAM credentials — and it is
  re-evaluated on EVERY call, because a name that resolves publicly once and
  privately the next time IS the rebind attack. Redirects are not followed, which
  matters more here than anywhere: a 302 would let a remote host bounce the
  request to an internal address AFTER the guard approved the public one, so a
  3xx is a failure and not a hop. A lowered `max_response_bytes`, checked AGAIN
  on this side because the client enforces its ceiling with a progress callback
  that can only act between chunks. And `retry => 0`.

- **The scheme is an allowlist**, because the set of dangerous schemes is
  open-ended — `file://`, `php://`, `data:`, `gopher://`, `jar:` — and the set of
  useful ones is two. Userinfo credentials are refused: they would land in our
  logs, and `user@host` is the oldest way to make a URL look like it points
  somewhere else. Control characters are refused, because a newline in a URL is a
  request-splitting primitive and also forges a line in our own log.

- **The content type is read from the BYTES, never from the remote header.** A
  host that declares `image/png` and sends HTML with a script in it would
  otherwise have those bytes re-served from your origin as an image, and a
  browser without `nosniff` would sniff the real type and run it. `null` from the
  signature check means REFUSE and explicitly not "fall back to the declared
  type" — that fallback is the whole vulnerability.

- **Remote SVG is refused and will stay refused.** Re-serving a stranger's
  scriptable document from your origin is stored XSS with no exploit required: an
  attacker needs only to host a file and paste its URL into an avatar field. It is
  also not detectable by magic bytes, because XML can begin with a declaration, a
  comment, a DOCTYPE, a BOM or whitespace — any prefix allowlist would be
  bypassable, and there is a test per preamble. Generating SVG while refusing SVG
  is not a contradiction: one is a document whose entire text this code chose, the
  other is a file a stranger uploaded.

- **A RIFF container is checked at offset 8, not just at 0.** A WAV file also
  begins with `RIFF`, so a four-byte prefix check would accept one as a WebP.

- **`http.client` is optional, on purpose.** Generating initials needs nothing,
  so requiring it would make every route that wants an initials avatar load an
  HTTP client it never calls. A route that wants `remoteImage()` declares
  `"requires": ["avatar.generation", "http.client"]`, and calling it without that
  throws with a message naming exactly that line.

- **Sizes are CLAMPED, never rejected.** A size usually arrives from a query
  string, so a 500 on `?size=99999` would be a denial of service anybody could
  trigger with a URL. An unknown shape falls back to a circle rather than
  rendering nothing. All of it is normalised in the private constructor, so
  neither named constructor can bypass it.

- **Deliberately absent: icon sets, QR codes, favicon fetch, PNG.** Several
  hundred flag and logo assets with a licence each, carried by every install, when
  a project can use any icon set — including flag emoji, which need no files. A
  correct QR encoder is Reed-Solomon over GF(256) and hundreds of lines that
  cannot be verified without known-answer vectors, and a wrapper around an absent
  library would be untested glue. A favicon fetch has the same open-proxy problem
  and needs the site's HTML parsed for `<link rel=icon>`, so `/favicon.ico` alone
  would mostly not work. PNG needs a rasteriser AND an embedded font with its
  licence; the cost is that many email clients do not render SVG, which the README
  states rather than solving badly.

### Fixed during development

- **The SVG escaping was unpinned, and a revert check is what found it.**
  Removing the `htmlspecialchars` call passed the whole suite, because every
  hostile-NAME test goes through `Initials::of()` first and arrives with nothing
  left to escape — so the second layer of the XSS defence was indistinguishable
  from dead code. There is now a separate test that constructs the document
  directly with text `Initials` would never produce, which is exactly what a
  future change to the stripping would amount to. Both layers are held
  independently, and removing the escaping now fails six tests.

- **A claim about `ENT_XML1` was simply wrong.** A comment asserted that an
  apostrophe becomes a numeric reference because `&apos;` "is not defined in XML
  1.0 without a DTD". `&apos;` is one of the five entities XML 1.0 predefines,
  and `ENT_XML1` emits it. The comment and the test built on it are corrected:
  what `ENT_XML1` actually buys is that the output is confined to those five, so
  no HTML-only entity such as `&nbsp;` can appear in a document a strict XML
  parser reads.

- **An injection assertion was too crude to be correct.** A test asserted that
  the rendered SVG does not contain the substring `onload=`, which fails on SAFE
  output: an escaped hostile background legitimately contains those characters
  inside `fill="#fff&quot; onload=&quot;alert(1)"`. It now asserts on the parsed
  DOM — no `onload` attribute, the whole hostile string as one attribute value,
  and exactly three attributes on the element.

### Notes

- `immutable` in `headers()` is honest for a generated avatar and only there: the
  document is a pure function of the name and the size, so it can never change
  for a URL. A FETCHED image deliberately does not get it — a remote image can
  change, and a year-long immutable cache on somebody else's content would
  outlive the reason for it.
- **The service's call to `inkFor()` is unpinned.** A revert check replacing it
  with a hard-coded white passes the suite, because every shipped background is
  dark enough that white IS the computed answer. `inkFor()` itself is unit-tested
  against black, white and yellow, so the computation is held; what no test can
  distinguish today is whether that line calls it. It stays because the moment a
  pale colour joins the palette, the difference is the difference between readable
  initials and white-on-pale.
- **No real HTTP request has ever been made.** Every fetch test uses a recording
  fake, so what is established is that the gateway ASKS for the three guards, not
  that the adapter honours them — that part is covered by
  `hkm-plugin-http-client`'s own suite.
- **No browser or rasteriser has rendered the SVG.** It is well-formed XML with
  the right attributes; nobody has looked at one.
- A CJK or Arabic initial renders only if the viewer has a font covering it. This
  plugin embeds no font by choice, so that is a property of the viewer.
