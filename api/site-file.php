<?php

/*
|--------------------------------------------------------------------------
| robots.txt, sitemap.xml and llms.txt for this install
|--------------------------------------------------------------------------
|
| Each names the install's own address, so they can't be static files in a
| repository every company deploys. docker/site-files.conf maps the three public
| paths here; ?file= is for running without Apache.
|
| MCP_PUBLIC_URL is the MCP server's address, for llms.txt. Unset, it leaves
| that section out rather than pointing at someone else's server.
|
*/

require_once "db.php";
require_once "mailer.php";

$file = (string) ($_GET["file"] ?? basename((string) parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH)));
$name = portalName($pdo);
$mcp = rtrim(mailerSetting("MCP_PUBLIC_URL", ""), "/");

if ($file === "robots.txt") {
    header("Content-Type: text/plain; charset=UTF-8");
    ?>
# This site is almost entirely an authenticated admin/client portal.
# Nothing behind a login belongs in a search or AI index, so everything
# under these paths is disallowed for every crawler - no exception is
# carved out for any specific AI crawler. They get the same rules as
# everyone else: blocked from private areas, free to fetch whatever's left.
#
# Individual inquiry links (inquiry.html?name=...) are deliberately not
# referenced here or in sitemap.xml. They're handed out one-to-one to a
# specific person, not meant to be discovered by crawling or search.

User-agent: *
Disallow: /admin*
Disallow: /api/
Disallow: /uploads/
Disallow: /login.php
Disallow: /register.php
Disallow: /admin-login.html
Disallow: /dashboard.html
Disallow: /forms.html
Disallow: /demo/
Disallow: /user-*.html
Disallow: /survey*.html
Disallow: /inquiry.html
Disallow: /inquiries.html
Disallow: /inquiry-form.html
Disallow: /content.html
Disallow: /notifications.html
Disallow: /source-files*

<?= APP_URL !== "" ? "Sitemap: " . APP_URL . "/sitemap.xml\n" : "" ?>
<?php
    exit;
}

if ($file === "sitemap.xml") {
    header("Content-Type: application/xml; charset=UTF-8");
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<!--
  Deliberately minimal. Individual inquiry consultation links are excluded on
  purpose: each is handed to one specific person and isn't meant to be
  discovered by crawling.
-->
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= htmlspecialchars(APP_URL . "/", ENT_XML1, "UTF-8") ?></loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>
</urlset>
<?php
    exit;
}

if ($file === "llms.txt") {
    header("Content-Type: text/plain; charset=UTF-8");
    ?>
# <?= $name ?>


> This domain hosts a client/admin portal, not a public marketing site.
> Most of it requires a login and is not for AI access. Do not attempt to
> authenticate, register accounts, or submit forms on this site directly on
> behalf of a user.<?= $mcp !== "" ? " The one supported way for an AI assistant to send a consultation\n> inquiry is the MCP server below, and only after the person has seen their\n> final answers and confirmed them." : "" ?>


## What's public here

- Individual consultation inquiry forms, each reachable only via a
  specific link shared directly with one person
  (`inquiry.html?name=<slug>`). These are not listed here or in
  sitemap.xml because they aren't meant to be discovered by crawling.
- A small read-only API for looking up one named inquiry form's
  fields, at `/api/inquiry-lookup.php?name=<slug>` - requires knowing
  the exact name, same as the public form itself. See
  `/api/inquiry-lookup.php` for the response shape.
<?php if ($mcp !== "") { ?>

## MCP server

`<?= $mcp ?>/public/mcp` (Streamable HTTP, no login):

- `get_inquiry(name)` and the resource `inquiry://{name}` - read one
  named inquiry form. There is no way to list inquiries.
- The prompt `consultation_intake(name)` - walks a person through the
  form's questions one at a time.
- `prepare_inquiry_submission` then `submit_inquiry_response` - sends
  the person's answers. The first returns a summary and a confirmation
  token; show the summary to the person and submit only after they
  explicitly agree. The token is bound to those exact answers, expires,
  and cannot be reused to submit twice.

Never invent answers, and never submit for someone who has not seen and
confirmed what will be sent.

`<?= $mcp ?>/mcp` on the same server is the portal for staff and clients,
and requires each person's own login. It is not for anonymous use.
<?php } ?>

## What's private (do not access, index, or infer)

- Everything under `/admin*`, `/user-*`, `/dashboard.html`, `/forms.html`,
  `/notifications.html`, `/source-files*` - all require an
  authenticated session.
- Any submitted inquiry or survey answers, uploaded files, client
  records, or admin data. None of this is exposed by any public
  endpoint on this domain.

## Contact

For anything not covered above, this site does not currently offer
further public AI-facing access.
<?php
    exit;
}

http_response_code(404);
