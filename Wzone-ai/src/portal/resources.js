// client://{id}, project://{id} and form://{id} - the same records as the
// overview and get_* tools, as context a person can attach. Listing them
// returns only what the caller can see (PHP's scoping, as everywhere), up
// to the 100 most recent of each.

import { ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

import { fetchPortal, UNTRUSTED_NOTE } from "./call.js";
import * as S from "./schemas.js";

const LIST_LIMIT = 100;

const RESOURCES = [
  {
    name: "client",
    scope: "clients:read",
    title: "Client",
    description: "One client's overview: profile, team, forms, content, meetings and projects.",
    path: "clients.php",
    item: S.clientListItem,
    detail: S.clientOverview,
    label: (c) => (c.company_name ? `${c.company_name} (${c.name})` : c.name),
  },
  {
    name: "project",
    scope: "projects:read",
    title: "Project",
    description: "One project with its members and task updates.",
    path: "projects.php",
    item: S.projectItem,
    detail: S.projectDetail,
    label: (p) => `${p.title} - ${p.client.company_name || p.client.name}`,
  },
  {
    name: "form",
    scope: "forms:read",
    title: "Form",
    description: "One form with its questions and review state.",
    path: "forms.php",
    item: S.formItem,
    detail: S.formDetail,
    label: (f) => `${f.title} - ${f.client.company_name || f.client.name} (${f.status})`,
  },
];

export function registerResources(server, { auth, requestId, scopes }) {
  for (const r of RESOURCES) {
    if (!scopes.includes(r.scope)) continue;
    const tool = `resource_${r.name}`;

    server.registerResource(
      r.name,
      new ResourceTemplate(`${r.name}://{id}`, {
        list: async () => {
          const result = await fetchPortal({
            auth,
            requestId,
            tool,
            path: r.path,
            query: { limit: LIST_LIMIT },
            schema: z.object(S.page(r.item)),
          });
          if (!result.ok) throw new Error(result.message);
          return {
            resources: result.data.items.map((item) => ({
              uri: `${r.name}://${item.id}`,
              name: r.label(item),
              mimeType: "application/json",
            })),
          };
        },
      }),
      { title: r.title, description: r.description, mimeType: "application/json" },
      async (uri, { id }) => {
        if (!/^[1-9]\d{0,9}$/.test(String(id))) {
          throw new Error(`${r.name}:// takes a numeric id, e.g. ${r.name}://42`);
        }
        const result = await fetchPortal({
          auth,
          requestId,
          tool,
          path: r.path,
          query: { id },
          schema: z.object(r.detail),
        });
        if (!result.ok) throw new Error(result.message);
        return {
          contents: [
            {
              uri: uri.href,
              mimeType: "application/json",
              // Still valid JSON: the reminder rides along as a field.
              text: JSON.stringify({ _note: UNTRUSTED_NOTE, ...result.data }, null, 2),
            },
          ],
        };
      }
    );
  }
}
