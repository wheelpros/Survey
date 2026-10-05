import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";

import { logger } from "./logger.js";
import { lookupInquiry } from "./wzoneClient.js";

/*
| The shape get_inquiry hands back, declared once and used twice: as the
| tool's outputSchema (so clients and models get typed structuredContent
| instead of parsing JSON out of text) and to check what the PHP side
| actually returned before passing it on. If inquiry-lookup.php ever
| drifts from this, the caller gets a clean tool error and the log says
| why, rather than the SDK rejecting the result mid-protocol.
*/
const fieldSchema = z.object({
  id: z.number().int(),
  label: z.string(),
  // 'input' | 'textarea', legacy 'choice' | 'select' - kept open so a
  // new type added in PHP doesn't break the tool.
  type: z.string(),
  required: z.boolean(),
  options: z.array(z.string()),
});

const inquiryShape = {
  title: z.string(),
  intro_text: z.string().nullable(),
  status: z.string().describe("'active' accepts answers; 'inactive' is closed"),
  fields: z.array(fieldSchema),
};
const inquirySchema = z.object(inquiryShape);

function toolError(text) {
  return { isError: true, content: [{ type: "text", text }] };
}

/*
|--------------------------------------------------------------------------
| Building a fresh McpServer + transport per request, on purpose
|--------------------------------------------------------------------------
|
| @modelcontextprotocol/sdk < 1.26.0 has a disclosed cross-client data
| leak (CVE-2026-25536 / GHSA-345p-7cg4-v4c7) when a single
| StreamableHTTPServerTransport or McpServer instance is reused across
| more than one client connection in a stateless deployment - which is
| exactly the shape of this server (no session tracking, no per-client
| state to preserve).
|
| package.json already pins the SDK to >=1.26.0, which contains the
| fix. This per-request construction is a second, independent
| mitigation: even on a correctly-patched SDK, there is structurally no
| shared instance for a bug like that to leak across, because nothing
| here is shared - each request gets its own McpServer and its own
| transport, used once, then discarded. It's also the officially
| documented pattern for stateless deployments.
*/
export function buildServer({ requestId } = {}) {
  const server = new McpServer({
    name: "wzone-inquiries",
    version: "1.1.0",
  });

  server.registerTool(
    "get_inquiry",
    {
      title: "Look up a consultation inquiry form",
      description:
        "Look up the public consultation inquiry form registered under an " +
        "exact name (its URL slug, e.g. " +
        "'free-30-minute-business-growth-consultation' from " +
        "inquiry.html?name=free-30-minute-business-growth-consultation). " +
        "Returns the form's title, intro text, active/inactive status, and " +
        "its field definitions (label, type, required, options). This is " +
        "the same information anyone already sees by opening the public " +
        "link directly - the exact name must already be known; there is " +
        "no way to list or browse inquiries through this tool.",
      inputSchema: {
        name: z
          .string()
          .min(1)
          .max(200)
          .regex(
            /^[a-z0-9-]+$/,
            "must be lowercase letters, digits and dashes only, e.g. 'free-consultation'"
          )
          .describe("The inquiry's exact name/slug from its public link"),
      },
      outputSchema: inquiryShape,
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: true,
      },
    },
    async ({ name }) => {
      const started = Date.now();
      const done = (outcome, extra = {}) =>
        logger.info("get_inquiry_completed", {
          requestId,
          outcome,
          duration_ms: Date.now() - started,
          ...extra,
        });

      try {
        const { body, cached } = await lookupInquiry(name);

        if (!body || body.success !== true) {
          // Not found, invalid name, rate-limited by the PHP side. Not a
          // server malfunction, but the call did not produce an inquiry -
          // flagged isError so the model sees it as a failed lookup it
          // can recover from (ask for the right link, retry later).
          // An inactive inquiry is NOT this: it comes back as a normal
          // result with status 'inactive'.
          done("unavailable", { cached });
          return toolError(body?.message || "Inquiry not available");
        }

        const parsed = inquirySchema.safeParse({
          title: body.inquiry?.title,
          intro_text: body.inquiry?.intro_text ?? null,
          status: body.inquiry?.status,
          fields: body.fields,
        });

        if (!parsed.success) {
          logger.error("wzone_api_shape_mismatch", {
            requestId,
            issues: parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`),
          });
          done("bad_upstream_shape", { cached });
          return toolError("The WZONE inquiry API returned data in an unexpected shape.");
        }

        done("ok", { cached, status: parsed.data.status });
        return {
          // Text mirror of structuredContent, for clients that predate
          // structured tool output.
          content: [{ type: "text", text: JSON.stringify(parsed.data, null, 2) }],
          structuredContent: parsed.data,
        };

      } catch (err) {
        logger.error("get_inquiry_tool_failed", { requestId, error: err.message });
        done("upstream_error");
        return toolError("Unable to reach the WZONE inquiry API right now.");
      }
    }
  );

  return server;
}
