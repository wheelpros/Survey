// The authenticated MCP server (MCP_MODE=private): the W|ZONE portal for a
// signed-in person - staff or client - through their AI assistant.
//
// Built per request, like the public one (see ../server.js for why), and
// per *person*: a tool is only registered when the token's scopes allow it,
// so a client never even sees a staff tool and the model has fewer, more
// relevant tools to choose between. Every call goes to api/v1 with the
// person's own token, and PHP decides what they may see; this side only
// shapes the result.

import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";

import { logger } from "../logger.js";
import { callPortalApi } from "../wzoneClient.js";

function toolError(text) {
  return { isError: true, content: [{ type: "text", text }] };
}

/**
 * Calls api/v1 as the person behind `auth` and turns its envelope into an
 * MCP tool result. `shape` is the zod schema the data must match.
 */
export async function portalCall({ auth, requestId, tool, path, query, shape }) {
  const started = Date.now();
  const log = (outcome, extra = {}) =>
    logger.info("portal_tool_completed", {
      requestId,
      tool,
      outcome,
      principal: `${auth.extra?.principal?.kind}:${auth.extra?.principal?.id}`,
      duration_ms: Date.now() - started,
      ...extra,
    });

  let result;
  try {
    result = await callPortalApi(path, { token: auth.token, tool, requestId, query });
  } catch (err) {
    logger.error("portal_api_unreachable", { requestId, tool, error: err.message });
    log("upstream_error");
    return toolError("The W|ZONE portal isn't reachable right now. Try again in a moment.");
  }

  const { status, body } = result;
  if (!body?.ok) {
    log("refused", { upstream_status: status });
    return toolError(body?.error?.message || `The W|ZONE portal refused this (HTTP ${status}).`);
  }

  const parsed = shape.safeParse(body.data);
  if (!parsed.success) {
    logger.error("portal_api_shape_mismatch", {
      requestId,
      tool,
      issues: parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`),
    });
    log("bad_upstream_shape");
    return toolError("The W|ZONE portal returned data in an unexpected shape.");
  }

  log("ok");
  return {
    content: [{ type: "text", text: JSON.stringify(parsed.data, null, 2) }],
    structuredContent: parsed.data,
  };
}

const whoamiShape = {
  kind: z.enum(["admin", "user"]).describe("'admin' for W|ZONE staff, 'user' for a client"),
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  role: z.string().describe("owner, super_admin, seo_admin, account_manager - or client"),
  scopes: z.array(z.string()).describe("What this connection may do"),
  via: z.string(),
  connected_app: z.string().nullable().optional(),
  visible_clients: z.enum(["all", "assigned"]).optional(),
  visible_client_count: z.number().int().optional(),
  company_name: z.string().nullable().optional(),
};

const READ_ONLY = {
  readOnlyHint: true,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: false,
};

export function buildPortalServer({ requestId, auth }) {
  const server = new McpServer({ name: "wzone-portal", version: "0.1.0" });

  server.registerTool(
    "whoami",
    {
      title: "Who am I connected as",
      description:
        "Shows which W|ZONE portal account this connection acts as - name, " +
        "role, and what it is allowed to do (its scopes) - and, for staff, " +
        "how many clients they can see. Use it when unsure whether something " +
        "is possible before trying.",
      inputSchema: {},
      outputSchema: whoamiShape,
      annotations: READ_ONLY,
    },
    async () =>
      portalCall({
        auth,
        requestId,
        tool: "whoami",
        path: "me.php",
        shape: z.object(whoamiShape),
      })
  );

  return server;
}
