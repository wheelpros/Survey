// The authenticated MCP server (MCP_MODE=private): the W|ZONE portal for a
// signed-in person - staff or client - through their AI assistant.
//
// Built per request, like the public one (see ../server.js for why), and
// per *person*: a tool is only registered when the token's scopes allow it,
// so a client never even sees a staff tool and the model has fewer, more
// relevant tools to choose between. Every call goes to api/v1 with the
// person's own token, and PHP decides what they may see; this side only
// shapes the result.
//
//   tools.js      the read tools, one per api/v1 read, behind their scopes
//   writes.js     the changes, each prepared, shown, then confirmed
//   resources.js  client:// project:// form://
//   prompts.js    ready-made workflows, for staff and for clients
//   schemas.js    what api/v1 answers with
//   call.js       the one way into api/v1

import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";

import { serverIcons } from "../icons.js";
import { portalCall, UNTRUSTED_NOTE } from "./call.js";
import { registerPrompts } from "./prompts.js";
import { registerResources } from "./resources.js";
import * as S from "./schemas.js";
import { READ_ONLY, registerReadTools } from "./tools.js";
import { registerWriteTools } from "./writes.js";

export { portalCall } from "./call.js";

const INSTRUCTIONS = [
  "This server is the client portal, acting as the person who connected it -",
  "a staff member or a client. Every tool returns only what that person can see in the",
  "portal; a 'not found' can mean it exists but isn't theirs to see.",
  "It can do exactly what that person's portal permissions allow, and follows them as they",
  "change; start with whoami if unsure what that is. If a tool the person expects is missing,",
  "their role doesn't allow it - the Director changes that in Settings. The portal's pages map",
  "to tools: Dashboard is get_dashboard, Messages is list_messages / read_chat / send_message,",
  "Tracking is get_tracking and get_ai_activity, Forms is list_forms (client forms), and its",
  "Team Forms section is list_team_forms / get_team_form - forms admins send each other,",
  "sent with send_team_form and answered with answer_team_form. Staff: for anything about one",
  "client, get_client_overview answers most questions in one call, and search_clients finds",
  "the client's id. Clients: my_overview shows what's waiting on them and what's coming up.",
  "Lists are paged: pass next_cursor back as cursor for more.",
  "Changes take two steps: a tool such as propose_meeting or submit_my_form only prepares",
  "the change and returns a summary - show it to the person, and call confirm_change only",
  "once they have said yes. Never confirm on their behalf.",
  "Staff messages (list_messages, read_chat, send_message) are private chats between two admins:",
  "send only what the person asked you to say.",
  UNTRUSTED_NOTE,
].join(" ");

export function buildPortalServer({ requestId, auth }) {
  const server = new McpServer(
    { name: "client-portal", version: "0.5.0", ...serverIcons() },
    { instructions: INSTRUCTIONS }
  );
  const scopes = auth.scopes || [];
  const ctx = { auth, requestId, scopes };

  server.registerTool(
    "whoami",
    {
      title: "Who am I connected as",
      description:
        "Shows which portal account this connection acts as - name, " +
        "role, and what it is allowed to do (its scopes: exactly what the person's portal " +
        "permissions allow right now) - and, for staff, how many clients they can see. Use it when " +
        "unsure whether something is possible before trying.",
      inputSchema: {},
      outputSchema: S.whoami,
      annotations: READ_ONLY,
    },
    async () =>
      portalCall({
        auth,
        requestId,
        tool: "whoami",
        path: "me.php",
        schema: z.object(S.whoami),
      })
  );

  registerReadTools(server, ctx);
  registerWriteTools(server, ctx);
  registerResources(server, ctx);
  registerPrompts(server, ctx);

  return server;
}
