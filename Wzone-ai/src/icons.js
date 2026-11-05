import { portalUrl } from "./wzoneClient.js";

/*
| The connector's icon is the logo uploaded in the portal's admin Settings,
| which the portal serves at logo.png from its database. Clients
| find an icon one of two ways - the `icons` the server sends back from
| initialize, or a favicon on this origin - so both point there. Without
| either, Claude showed a broken image next to the connector's name.
|
| The favicon paths redirect rather than copy the file, so a new upload shows
| up without redeploying this server. 302, not 301: a permanent redirect
| would be cached as the answer for good.
*/
const LOGO_URL = portalUrl("logo.png");

export function mountIcons(app) {
  app.get(["/favicon.ico", "/icon.png", "/apple-touch-icon.png"], (req, res) => {
    res.redirect(302, LOGO_URL);
  });
}

/**
 * The `icons` entry for an McpServer's serverInfo. No mimeType: the upload
 * accepts PNG, JPG and SVG but the address is always logo.png, so the
 * extension says nothing reliable about what is inside.
 */
export function serverIcons() {
  return { icons: [{ src: LOGO_URL }] };
}
