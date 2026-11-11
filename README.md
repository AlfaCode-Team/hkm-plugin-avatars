# hkm-plugin-avatars

A deterministic initials avatar as SVG — a pure function of a name, with no
network, no database, no image library and no font file — plus a guarded fetch
for an avatar URL somebody supplied.

```php
$avatar = $avatars->initials('José María García', size: 128, shape: 'circle');

$avatar->toSvg();        // ~450 bytes, deterministic, 'JG' on a stable colour
$avatar->toDataUri();    // data:image/svg+xml;base64,… for an <img src>
$avatar->headers();      // the CSP and nosniff this document must be served with
```

| | |
|---|---|
| `solves` | `avatar.generation` |
| `requires` | nothing — `http.client` is **optional** |
| `exposes` | `AvatarContract` |
| Kernel floor | `^1.3.3` |
| Routes / commands | none — see below |
| Needs | nothing: no GD, no Imagick, no font, no table |

---

## `Initials::of()` is not `$name[0]`

Every naive implementation of this is wrong in a way that only shows up on
somebody else's name — which is to say in production, and never in the fixture
whose author is called John Smith.

**Graphemes, not code points.** Measured on PHP 8.5 / ICU 79:

```
"école" with a decomposed é (e + U+0301)
    mb_substr($s, 0, 1)       → 0x65           just "e" — the accent is gone
    grapheme_substr($s, 0, 1) → 0x65 0xCC81    "é"

a family emoji 👨‍👩‍👧 (three people joined by ZWJs)
    mb_strlen($s)       → 5
    grapheme_strlen($s) → 1
```

So `mb_substr($s, 0, 1)` on that second name emits **half a ZWJ sequence** — a
lone emoji followed by a dangling joiner, which renders as a broken glyph. This
uses `grapheme_*`, with a `\X`-based UTF-8 fallback for a build that has neither
`ext-intl` nor the Symfony polyfill. The fallback keeps combining marks attached
and does **not** understand ZWJ sequences, which is stated rather than hidden.

**First and last word, never the first two.** `José María García` → `JG`, not
`JM`. Taking the first two gives the given name and the *middle* name, which is
not how anyone abbreviates a name — the short form is "José García".

**A single word gives one letter.** `Cher` → `C`, never `CH`: two letters from
one word read as the initials of two names, so a mononym would appear to have a
surname. Which also handles scripts without spaces — `山田太郎` → `山`.

**A name with no letters gives nothing.** `''`, `'   '` and `'...'` all yield
`''`, and the avatar is a plain coloured tile. Not `'?'`: that is a character the
user never typed, appearing where their name should be.

**`ß` stays one glyph.** `mb_strtoupper('ß')` is `'SS'` — two characters from one
— so the result is re-cut to a single grapheme.

---

## Determinism, and contrast that is computed

The colour comes from **SHA-256 of the case-folded, whitespace-collapsed name**.
Never `rand()`, `mt_rand()` or `uniqid()`: an avatar that changes colour between
two page loads reads as a different person. `crc32` is stable but clusters badly
on short similar strings — exactly the input here — so `Ann Smith` and
`Ann Smyth` land in different buckets.

Case folding is `mb_strtolower`, not `strtolower`, so `Égypte` and `égypte` are
one person. Without the whitespace collapse, an avatar would change colour the
day a profile form trimmed a trailing space.

The **ink is chosen by WCAG 2.1 relative luminance**, not fixed to white. The
usual mistake is white text on a fixed palette, which fails the moment somebody
adds a pale colour — and initials in white on light yellow are unreadable rather
than merely ugly. There is a test asserting that **every one of the 16 palette
entries** clears 4.5:1 with its computed ink, so a palette edit that breaks
accessibility fails the suite instead of shipping.

> The luminance calculation linearises each channel before weighting — the step
> people skip. `#808080` is halfway in sRGB and about **21.6%** in luminance; a
> naive `128/255 = 0.502` is wrong by more than a factor of two. Removing the
> linearisation fails 18 tests.

---

## SVG, and what that costs

SVG needs no GD, no Imagick, no font file and no licence for one. It is ~450
bytes, scales to any size, and renders crisply on every display.

**The consequence is that SVG is a scriptable document.** An SVG served from your
own origin can carry `<script>` and run with your origin's privileges, which
makes a user-controlled SVG stored XSS — and the text here comes from a person's
*name*.

Two independent defences:

1. `Initials::of()` strips everything that is not a letter or a digit, so by
   construction the text cannot contain `<`, `>`, `&` or a quote.
2. The text is escaped on the way into the document anyway.

Either alone would do today, and **a revert check proved that was a problem**:
removing the escaping passed the whole suite, because every hostile-*name* test
went through the stripping first and arrived with nothing left to escape. There
is now a separate test that constructs the document directly with text
`Initials` would never produce — which is exactly what a future change to the
stripping would amount to. Both layers are held independently.

### Serving it

```
Content-Type: image/svg+xml
Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=31536000, immutable
```

`headers()` returns exactly that. `immutable` is honest here and only here: the
document is a pure function of the name and the size, so it can never change for
a given URL — and there is a test asserting it is byte-identical across renders.

Or use **`toDataUri()`** inside an `<img src>`, which is the safest option: a
browser will not execute script in an SVG loaded through `<img>` whatever the
document contains, so whether the text was escaped stops being a security
question at all.

> `dy=".35em"` rather than `dominant-baseline="central"`. Both centre text in a
> browser; `dominant-baseline` is unsupported or mishandled by **librsvg**, which
> is what ImageMagick and most server-side SVG-to-PNG converters use — so an
> avatar centred in Chrome would sit high when rasterised for an email.

---

## The remote fetch — the one dangerous thing here

Appwrite exposes this capability as `GET /avatars/image?url=…`: an
unauthenticated endpoint that fetches an arbitrary URL and returns the bytes.
**That is an open proxy.** Anyone on the internet can use the server to reach
things it can reach and they cannot — a metadata service, an internal admin
panel, a database's HTTP interface — and read the answer out of the response. It
is also a free bandwidth amplifier.

So this plugin ships **no route**. The capability is a service method, and the
application decides who may call it and caches the result.

Within that, every outbound default is overridden:

| | |
|---|---|
| `block_private => true` | The HTTP client allows private ranges by default — right for a first-party gateway, exactly wrong for a URL a user typed. Without it, `http://169.254.169.254/latest/meta-data/` reads IAM credentials. Re-evaluated on **every** call, because a name that resolves publicly once and privately the next time *is* the rebind attack. |
| redirects not followed | Already the client's behaviour, and it matters more here than anywhere: a 302 would let a remote host bounce the request to an internal address **after** the guard approved the public one it was given. A 3xx is treated as a failure, not a hop. |
| `max_response_bytes` | The reply is an avatar. A hostile host could otherwise stream filler until the process ran out of memory, from one request. Checked again on this side, because the client enforces its ceiling with a progress callback that can only act *between* chunks. |
| `retry => 0` | A failed avatar fetch is not worth three attempts. |

**The scheme is an allowlist** — `http` and `https`, nothing else — because the
set of dangerous schemes is open-ended (`file://`, `php://`, `data:`, `gopher://`,
`jar:`) and the set of useful ones is two. Userinfo credentials
(`https://user:pass@host/`) are refused: they would land in our logs, and
`user@host` is the oldest way to make a URL look like it points somewhere else.
Control characters are refused, because a newline in a URL is a request-splitting
primitive and also forges a line in our own log.

### The content type is read from the bytes

Never from the remote header. A host that declares `image/png` and sends HTML
with a script in it would otherwise have those bytes re-served from your origin
as an image, and a browser without `nosniff` would sniff the real type and run
it. `ImageSignature` reads magic bytes; `null` means **refuse** and explicitly
does not mean "fall back to the declared type" — that fallback is the whole
vulnerability.

| Accepted | PNG, JPEG, GIF, WebP, BMP, ICO |
|---|---|
| **Refused** | **SVG**, and everything else |

**Remote SVG is refused and will stay refused.** Re-serving a stranger's
scriptable document from your origin is stored XSS with no exploit required — an
attacker needs only to host a file and paste its URL into an avatar field. It is
also not detectable by magic bytes: SVG is XML, so it can begin with a
declaration, a comment, a DOCTYPE, a BOM or whitespace, and any prefix-based
allowlist would be bypassable. There is a test for each of those preambles.

> Generating SVG and refusing SVG is not a contradiction: one is a document whose
> entire text this code chose, the other is a file a stranger uploaded.

### `http.client` is optional

Generating initials needs nothing, so declaring `http.client` as a requirement
would make every route that wants an initials avatar load an HTTP client it never
calls. The fetcher is bound only when `HttpClientPort` is already resolvable, and
a route that wants `remoteImage()` declares:

```jsonc
"requires": ["avatar.generation", "http.client"]
```

Calling it without that throws with a message naming exactly that line.

---

## Deliberately absent

**No icon sets.** Appwrite serves browser logos, credit-card logos and ~250
country flags. That is several hundred binary assets with a licence each, carried
by every install, when a project can use any icon set it likes — including the
flag emoji, which need no files at all.

**No QR codes.** A correct encoder is Reed-Solomon arithmetic over GF(256) and
several hundred lines that cannot be verified without known-answer vectors. A
thin wrapper around a library this plugin does not depend on would be untested
glue, which is worse than not having it: `bacon/bacon-qr-code` does the job and
belongs where the project requires it.

**No favicon fetch.** Same open-proxy problem, and the reliable implementation
needs the site's HTML parsed for `<link rel="icon">` — a second fetch, an HTML
parser and a much wider surface. `/favicon.ico` alone fails for most modern
sites, so shipping it would be a feature that mostly does not work.

**No PNG.** Rasterising needs GD or Imagick *and* a font file to embed, with the
licence that implies. The one place this costs something is **email**: many
clients do not render SVG, so an avatar in an email needs a rasteriser. Pair with
`image.processing` and a font if you need that — it is not solved here, and that
is better than solving it badly.

**No routes, no commands.** An initials avatar is a pure function the application
serves from its own route under its own URL. There is no state to inspect, prune
or repair, so a command would have nothing to do.

---

## Configuration

| Key | Default | |
|---|---|---|
| `AVATARS_DEFAULT_SIZE` | `128` | clamped to 16–1024 |
| `AVATARS_DEFAULT_SHAPE` | `circle` | `circle`, `square`, `rounded` |
| `AVATARS_MAX_FETCH_BYTES` | `2097152` | clamped to 4 KB–16 MB |
| `AVATARS_FETCH_TIMEOUT` | `8` | clamped to 1–60 |
| `AVATARS_ALLOW_PRIVATE_FETCH` | `false` | **re-opens the SSRF surface** |

A requested size is **clamped, never rejected**: it usually arrives from a query
string, so a 500 on `?size=99999` would be a denial of service anybody could
trigger with a URL. An unknown shape falls back to a circle rather than rendering
nothing.

Every value is normalised in the **private constructor**, so neither named
constructor can bypass it — the mistake found and fixed in the resource-tokens
plugin, where the validation lived in `fromEnv()` only.

`describe()` prints `block_private`, so a deployment that re-opened the SSRF
surface has it visible in a status line rather than only in an `.env` file.

---

## Verified

- **199 tests, 790 assertions**, green. `ground check --strict`: clean.
- **Every palette entry clears WCAG AA 4.5:1** with its computed ink, as a
  per-colour test.
- The luminance maths is checked against the WCAG coefficients (0.2126 / 0.7152 /
  0.0722) and the `#808080` ≈ 21.6% case.
- The generated SVG is **parsed** with `DOMDocument`, not string-matched, so
  malformed output fails with the parser's own complaint.
- **Nine hostile names driven end to end**, plus **six hostile initials driven
  into the document directly** so the escaping layer is held on its own.
- The three outbound options (`block_private`, `max_response_bytes`, `retry`) are
  asserted on the recorded call, and `block_private` is checked again on a second
  fetch.
- Fifteen refused URL shapes, each confirmed to make **no outbound call at all**.
- Seven SVG preambles confirmed undetectable as images; HTML declared as
  `image/png` confirmed refused; a WAV confirmed not to pass as a WebP.
- **Fourteen revert checks**, twelve of which failed. The two that passed are
  recorded below, and one of them produced a new test.

## NOT verified

- **No real HTTP request has ever been made.** Every fetch test uses a recording
  fake, so what is established is that the gateway *asks* for the three guards —
  not that the adapter honours them. That part is covered by the
  `hkm-plugin-http-client` suite, where `block_private` was added and pinned.
  **This is the largest gap.**
- **No browser or rasteriser has rendered the SVG.** It is well-formed XML with
  the right attributes; nobody has looked at one. In particular the `dy=".35em"`
  choice is reasoned from librsvg's known handling of `dominant-baseline`, not
  measured against librsvg.
- **The contrast ratios are computed by this plugin's own code**, so the test
  asserts the palette is consistent with *our* implementation of WCAG. The
  implementation is checked against the published coefficients and three known
  values, which is as close to independent as this suite gets.
- **The service's call to `inkFor()` is unpinned.** A revert check replacing it
  with a hard-coded white passes the whole suite, because every shipped
  background is dark enough that white *is* the computed answer. `inkFor()`
  itself is unit-tested against black, white and yellow, so the computation is
  held — what no test can distinguish today is whether that line calls it. It
  stays because the moment a pale colour joins the palette, the difference is the
  difference between readable initials and white-on-pale.
- **The non-ICU grapheme fallback has never run here**, because `ext-intl` is
  loaded. It is reached only on a build with neither the extension nor the
  Symfony polyfill, and its ZWJ limitation is stated rather than tested.
- **No font rendering is verified.** A CJK or Arabic initial renders only if the
  viewer has a font covering it; with none it falls back to a replacement box.
  That is a property of the viewer, and this plugin embeds no font by choice.

---

## Licence

MIT. See `LICENSE`.
