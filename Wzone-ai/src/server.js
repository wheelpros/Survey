import { z } from "zod";
import { McpServer, ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";

import { logger } from "./logger.js";
import { canSubmitUpstream, lookupInquiry, publicFormUrl, submitToWzone } from "./wzoneClient.js";
import { canonicalAnswers, idempotencyKey, issueToken, verifyToken } from "./confirmation.js";
import { allowSubmit } from "./submitLimiter.js";

// Signs confirmation tokens. Without it (or without the upstream key that
// api/inquiry-submit.php demands) the submit tools are simply not offered -
// the server stays the read-only one it was.
const SIGNING_KEY = process.env.MCP_SIGNING_KEY || "";
const SUBMIT_ENABLED = SIGNING_KEY.length >= 32 && canSubmitUpstream;

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

const nameSchema = z
  .string()
  .min(1)
  .max(200)
  .regex(
    /^[a-z0-9-]+$/,
    "must be lowercase letters, digits and dashes only, e.g. 'free-consultation'"
  )
  .describe("The inquiry's exact name/slug from its public link");

const answersSchema = z
  .array(
    z.object({
      field_id: z.number().int().positive().describe("The field's id from get_inquiry"),
      value: z
        .union([z.string().max(2000), z.array(z.string().max(500)).max(50)])
        .describe("The person's answer - a string, or a list for a multi-choice field"),
    })
  )
  .min(1)
  .max(100)
  .refine(
    (answers) => new Set(answers.map((a) => a.field_id)).size === answers.length,
    "each field_id may appear only once"
  )
  .describe("One entry per answered field, in the person's own words");

function toolError(text) {
  return { isError: true, content: [{ type: "text", text }] };
}

/**
 * Fetches, checks and shapes one inquiry - shared by the get_inquiry tool
 * and the inquiry:// resource so they can never disagree.
 * Resolves to { ok: true, data, cached } or { ok: false, message, outcome }.
 */
async function loadInquiry(name, requestId) {
  const { body, cached } = await lookupInquiry(name);

  if (!body || body.success !== true) {
    // Not found, invalid name, rate-limited by the PHP side.
    return { ok: false, outcome: "unavailable", cached, message: body?.message || "Inquiry not available" };
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
    return {
      ok: false,
      outcome: "bad_upstream_shape",
      cached,
      message: "The inquiry API returned data in an unexpected shape.",
    };
  }

  return { ok: true, data: parsed.data, cached };
}

function timed(requestId, event) {
  const started = Date.now();
  return (outcome, extra = {}) =>
    logger.info(event, { requestId, outcome, duration_ms: Date.now() - started, ...extra });
}

function intakePrompt(name) {
  const finish = SUBMIT_ENABLED
    ? [
        "6. When they confirm, call prepare_inquiry_submission with the inquiry name and",
        "   their answers. Show them the summary it returns, exactly as returned, and ask",
        "   plainly whether to send it. If prepare reports a problem (a required question",
        "   missed, an answer too long), fix that one thing with them and prepare again.",
        "7. Only after an explicit yes, call submit_inquiry_response with the SAME name,",
        "   the SAME answers and the confirmation_token. If they want to change anything,",
        "   go back to step 6 - a changed answer needs a new token.",
        "8. Tell them it was sent. If the submit result is unclear (a timeout), retrying",
        "   with the same token is safe - it can never be stored twice.",
      ]
    : [
        `6. This server cannot submit for them. Give them the link ${publicFormUrl(name)}`,
        "   and the answers you collected, so they can paste them in and send it themselves.",
      ];

  return [
    `Help me answer the consultation inquiry "${name}".`,
    "",
    "Work through it like this:",
    `1. Call get_inquiry with name "${name}". If it can't be found, say so and stop.`,
    "   If its status is 'inactive', tell me it isn't accepting responses and stop.",
    "2. Show me the title and intro text, then ask the questions ONE AT A TIME, in",
    "   order, using each field's label as written. Say which ones are optional.",
    "3. Respect the form's rules as you go:",
    "   - required fields need an answer;",
    "   - 'input' answers are at most 120 characters, 'textarea' at most 800 -",
    "     if mine is longer, help me shorten it rather than cutting it off;",
    "   - for 'choice' / 'select' fields, offer only the listed options",
    "     ('select' takes one, 'choice' may take several).",
    "4. Use only what I actually tell you. Never invent, guess or pad an answer,",
    "   and leave an optional question empty if I skip it.",
    "5. At the end, list every question with my answer and ask me to confirm or",
    "   correct them.",
    ...finish,
  ].join("\n");
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
| documented pattern for stateless deployments - and the reason
| confirmations are signed tokens (confirmation.js), not elicitation.
*/
export function buildServer({ requestId, clientIp } = {}) {
  const server = new McpServer({
    name: "consultation-inquiries",
    version: "1.2.0",
  });

  // ── Read ──────────────────────────────────────────────────────────────

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
        "its field definitions (id, label, type, required, options). This is " +
        "the same information anyone already sees by opening the public " +
        "link directly - the exact name must already be known; there is " +
        "no way to list or browse inquiries through this tool.",
      inputSchema: { name: nameSchema },
      outputSchema: inquiryShape,
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: true,
      },
    },
    async ({ name }) => {
      const done = timed(requestId, "get_inquiry_completed");
      try {
        const result = await loadInquiry(name, requestId);
        done(result.ok ? "ok" : result.outcome, { cached: result.cached });

        if (!result.ok) {
          // The call did not produce an inquiry - flagged isError so the
          // model treats it as a failed lookup it can recover from (ask for
          // the right link, retry later). An inactive inquiry is NOT this:
          // it comes back as a normal result with status 'inactive'.
          return toolError(result.message);
        }
        return {
          // Text mirror of structuredContent, for clients that predate
          // structured tool output.
          content: [{ type: "text", text: JSON.stringify(result.data, null, 2) }],
          structuredContent: result.data,
        };
      } catch (err) {
        logger.error("get_inquiry_tool_failed", { requestId, error: err.message });
        done("upstream_error");
        return toolError("Unable to reach the inquiry API right now.");
      }
    }
  );

  server.registerResource(
    "inquiry",
    // list: undefined on purpose - the same "you must already know the
    // name" boundary as the tool and the public link. Nothing enumerates.
    new ResourceTemplate("inquiry://{name}", { list: undefined }),
    {
      title: "Consultation inquiry form",
      description: "One public inquiry form by its exact name, as JSON - the same data as get_inquiry.",
      mimeType: "application/json",
    },
    async (uri, { name }) => {
      const parsedName = nameSchema.safeParse(String(name));
      if (!parsedName.success) {
        throw new Error("Inquiry names are lowercase letters, digits and dashes only");
      }
      const result = await loadInquiry(parsedName.data, requestId);
      if (!result.ok) throw new Error(result.message);
      return {
        contents: [
          { uri: uri.href, mimeType: "application/json", text: JSON.stringify(result.data, null, 2) },
        ],
      };
    }
  );

  server.registerPrompt(
    "consultation_intake",
    {
      title: "Answer a consultation inquiry",
      description:
        "Walk a person through a consultation form one question at a time" +
        (SUBMIT_ENABLED ? ", then send it once they confirm." : "."),
      argsSchema: { name: nameSchema },
    },
    ({ name }) => ({
      messages: [{ role: "user", content: { type: "text", text: intakePrompt(name) } }],
    })
  );

  if (!SUBMIT_ENABLED) return server;

  // ── Submit: prepare → person confirms → submit ────────────────────────

  const preparedShape = {
    title: z.string(),
    answers: z.array(
      z.object({ field_id: z.number().int(), label: z.string(), type: z.string(), value: z.string() })
    ),
    confirmation_token: z.string(),
    expires_at: z.string(),
  };

  server.registerTool(
    "prepare_inquiry_submission",
    {
      title: "Check answers before sending an inquiry",
      description:
        "Step 1 of 2 for sending someone's answers to a consultation inquiry. " +
        "Checks the answers exactly as the form would (required questions, " +
        "length limits, allowed options) WITHOUT sending anything, and returns " +
        "a summary plus a confirmation_token valid for 15 minutes. Show the " +
        "person the summary and send it with submit_inquiry_response only " +
        "after they explicitly say yes. Never invent answers.",
      inputSchema: { name: nameSchema, answers: answersSchema },
      outputSchema: preparedShape,
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: true,
      },
    },
    async ({ name, answers }) => {
      const done = timed(requestId, "prepare_inquiry_submission_completed");
      try {
        const { status, body } = await submitToWzone({ name, answers, dryRun: true });

        if (status !== 200 || body?.success !== true) {
          done("refused", { status });
          return toolError(body?.message || "Those answers couldn't be checked right now.");
        }

        const { token, expiresAt } = issueToken(
          SIGNING_KEY,
          `inquiry:${name}`,
          canonicalAnswers(answers)
        );
        const result = {
          title: String(body.inquiry?.title ?? ""),
          answers: (body.answers || []).map((a) => ({
            field_id: Number(a.field_id),
            label: String(a.label),
            type: String(a.type),
            value: String(a.value ?? ""),
          })),
          confirmation_token: token,
          expires_at: expiresAt,
        };

        const summary = result.answers
          .map((a) => `- ${a.label}: ${a.value === "" ? "(left blank)" : a.value}`)
          .join("\n");

        done("ok");
        return {
          content: [
            {
              type: "text",
              text:
                `Nothing has been sent yet. Show the person this and ask them to confirm:\n\n` +
                `${result.title}\n${summary}\n\n` +
                `If they say yes, call submit_inquiry_response with the same name and ` +
                `answers and confirmation_token "${token}" (valid until ${expiresAt}).`,
            },
          ],
          structuredContent: result,
        };
      } catch (err) {
        logger.error("prepare_inquiry_submission_failed", { requestId, error: err.message });
        done("upstream_error");
        return toolError("Unable to reach the inquiry API right now.");
      }
    }
  );

  server.registerTool(
    "submit_inquiry_response",
    {
      title: "Send a confirmed inquiry",
      description:
        "Step 2 of 2: sends the answers. Call ONLY after the person " +
        "has seen the summary from prepare_inquiry_submission and explicitly " +
        "agreed. Pass the same name and the same answers, plus its " +
        "confirmation_token - if any answer changed, prepare again and get a " +
        "fresh confirmation. Retrying with the same token is safe; it is " +
        "never stored twice.",
      inputSchema: {
        name: nameSchema,
        answers: answersSchema,
        confirmation_token: z
          .string()
          .max(100)
          .describe("The token prepare_inquiry_submission returned for these exact answers"),
      },
      outputSchema: {
        submitted: z.boolean(),
        duplicate: z.boolean().describe("true if this exact confirmed submission had already been received"),
        message: z.string(),
      },
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: true,
      },
    },
    async ({ name, answers, confirmation_token }) => {
      const done = timed(requestId, "submit_inquiry_response_completed");

      const check = verifyToken(
        SIGNING_KEY,
        confirmation_token,
        `inquiry:${name}`,
        canonicalAnswers(answers)
      );
      if (!check.ok) {
        done(`token_${check.reason}`);
        return toolError(
          check.reason === "expired"
            ? "That confirmation has expired. Call prepare_inquiry_submission again and re-confirm with the person."
            : "These answers don't match what the person confirmed. Call prepare_inquiry_submission with the final answers and get their confirmation again."
        );
      }

      if (!allowSubmit(clientIp)) {
        done("rate_limited");
        return toolError("Too many submissions from this connection - please try again later.");
      }

      try {
        const { status, body } = await submitToWzone({
          name,
          answers,
          dryRun: false,
          submissionKey: idempotencyKey(confirmation_token),
        });

        if (status !== 200 || body?.success !== true) {
          done("refused", { status });
          return toolError(body?.message || "The inquiry was not sent.");
        }

        const result = {
          submitted: true,
          duplicate: body.duplicate === true,
          message: body.duplicate
            ? "Already received earlier - nothing was sent twice."
            : "Sent. The answers have been received.",
        };
        done("ok", { duplicate: result.duplicate });
        return {
          content: [{ type: "text", text: result.message }],
          structuredContent: result,
        };
      } catch (err) {
        logger.error("submit_inquiry_response_failed", { requestId, error: err.message });
        done("upstream_error");
        return toolError(
          "Couldn't confirm whether it was sent - the server didn't answer in time. " +
            "It is safe to call submit_inquiry_response again with the same token; " +
            "it will never be stored twice."
        );
      }
    }
  );

  return server;
}
