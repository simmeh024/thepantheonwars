# Browser security headers

The site-wide policy is configured in [`.htaccess`](../.htaccess). Apache's
`mod_headers` applies it to static pages and PHP responses, so the headers are
visible on both page and API requests in browser developer tools.

| Header | Value / purpose |
| --- | --- |
| `Content-Security-Policy` | Defaults to same-origin resources, blocks plugins, framing, non-local form submissions and unapproved scripts. External Google font styles/fonts and the Spotify embed are limited to their required origins. |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains`; browsers that have received it use HTTPS for this domain and its subdomains for two years. It is only honoured over HTTPS. |
| `X-Content-Type-Options` | `nosniff`, preventing MIME-type guessing for responses and scripts. |
| `X-Frame-Options` and CSP `frame-ancestors` | Both deny framing, including by same-origin pages, to prevent clickjacking. |
| `Referrer-Policy` | `strict-origin-when-cross-origin`, preserving useful same-origin referrers while not leaking full paths to other sites. |
| `Permissions-Policy` | Disables geolocation, microphone, camera, payment and USB APIs unless the policy is deliberately expanded. |
| `X-XSS-Protection` | Set to `0`; modern browsers rely on CSP and their built-in protections instead of the obsolete, error-prone legacy filter. |

## Inline-script policy

`script-src` includes a temporary compatibility `unsafe-inline` exception for
legacy inline scripts. The shared host rewrites those bytes after deployment,
and the rewrite is outside the PageSpeed module, so a hash policy cannot be
made stable. External scripts remain limited to same-origin only.

Do not add new inline scripts or HTML event handlers; use versioned local
JavaScript listeners instead. The next CSP hardening phase is to externalize
each legacy inline block, then remove this exception.

```powershell
$utf8 = [System.Text.UTF8Encoding]::new($false)
$sha = [System.Security.Cryptography.SHA256]::Create()
Get-ChildItem -Recurse -Filter *.html | ForEach-Object {
  $content = [System.IO.File]::ReadAllText($_.FullName, $utf8)
  [regex]::Matches($content, '<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)</script>', 'IgnoreCase') |
    ForEach-Object { "'sha256-$([Convert]::ToBase64String($sha.ComputeHash($utf8.GetBytes($_.Groups[1].Value)))'" }
}
$sha.Dispose()
```

Keep the policy restrictive: prefer a versioned external JavaScript file for
new behaviour. If an inline block is unavoidable, add only its newly generated
hash; never add `unsafe-inline` to `script-src`.

## First-visit HTTPS protection

HSTS only takes effect after a browser first receives it over HTTPS. The root
`.htaccess` therefore also redirects every HTTP request to the canonical HTTPS
origin with a permanent `301`, preserving the route and query string. Test both
the redirect and the HTTPS response after deployment:

```powershell
curl.exe -I http://thepantheonwars.com/
curl.exe -I https://thepantheonwars.com/
```
