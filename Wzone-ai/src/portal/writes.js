// The staff write tools. Every change goes in two steps, through
// api/v1/changes.php:
//
//   1. a "prepare" tool - create_form_draft, review_form, ... - checks the
//      change as the person and returns a plain summary plus a single-use
//      confirmation_token. Nothing changes yet, so these are read-only.
//   2. confirm_change(confirmation_token, summary) makes exactly the change
//      that was prepared. PHP kept the arguments, so they can't be altered
//      in between; the summary is passed back so the person's AI client,
//      which asks them before running a non-read-only tool, shows them what
//      they are approving rather than a token.
//
// Elicitation would be the protocol's way to ask, but this server is
// stateless (see ../server.js), so the confirmation is a token instead -
// the same reasoning as the public server's inquiry submission.
//
// mark_notifications_read is the one write that skips this: it only ever
// touches the person's own inbox.

import { z } from "zod";

import { portalCall } from "./call.js";
import * as S from "./schemas.js";
import { CONTENT_TYPE_IDS } from "./tools.js";

const PREPARE = {
  readOnlyHint: true, // preparing writes nothing
  destructiveHint: false,
  idempotentHint: false,
  openWorldHint: false,
};

const recordId = (what) => z.number().int().positive().describe(what);
const clientId = recordId("The client's id - from search_clients");
const date = (what) => z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "must be a date like 2026-10-05").describe(what);
const time = (what) => z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, "must be a 24-hour time like 14:30").describe(what);

const TWO_STEP =
  " Step 1 of 2: nothing changes yet. Show the person the summary this returns, " +
  "exactly, and call confirm_change only after they say yes.";

export const WRITE_TOOLS = [
  {
    name: "save_my_client_note",
    scope: "clients:write",
    title: "Save my note about a client",
    description: "Replaces your own private note about a client (an empty text clears it). Nobody else can see it." + TWO_STEP,
    input: {
      client: clientId,
      text: z.string().max(5000).describe("The whole note, as it should read afterwards"),
    },
  },
  {
    name: "create_form_draft",
    scope: "forms:write",
    title: "Draft a form for a client",
    description:
      "Creates a form for one of your clients. It always goes into the review queue - the client " +
      "sees it only after a reviewer approves it, even if you are a reviewer. Every question is " +
      "required, as in the portal's form builder." + TWO_STEP,
    input: {
      client: clientId,
      title: z.string().min(1).max(255),
      description: z.string().max(5000).optional().describe("Shown to the client above the questions"),
      questions: z
        .array(
          z.object({
            text: z.string().min(1).max(1000),
            type: z
              .enum(["input", "textarea", "checkbox", "file"])
              .optional()
              .describe("input: one line (default); textarea: a paragraph; checkbox: pick from options; file: an upload"),
            options: z.array(z.string().min(1).max(100)).max(20).optional().describe("Checkbox questions only - at least two"),
            max_file_size_mb: z.number().int().min(1).max(50).optional().describe("File questions only"),
          })
        )
        .min(1)
        .max(50),
    },
  },
  {
    name: "review_form",
    scope: "forms:review",
    title: "Approve or return a form",
    description:
      "Decides a form waiting in the review queue: 'approve' releases it to its client now; " +
      "'return' sends it back to its author with your comment (required)." + TWO_STEP,
    input: {
      id: recordId("The form's id - from list_forms_awaiting_review"),
      decision: z.enum(["approve", "return"]),
      comment: z.string().max(1000).optional().describe("Required to return it: what the author should change"),
    },
  },
  {
    name: "create_inquiry_draft",
    scope: "inquiries:write",
    title: "Draft a consultation inquiry",
    description:
      "Creates a public consultation inquiry, closed: nobody can answer it until it is opened on " +
      "the portal's Inquiries page." + TWO_STEP,
    input: {
      title: z.string().min(1).max(200),
      intro: z.string().max(2000).optional().describe("Shown above the questions"),
      fields: z
        .array(
          z.object({
            label: z.string().min(1).max(200),
            type: z.enum(["input", "textarea"]).optional().describe("input: up to 120 characters (default); textarea: up to 800"),
            required: z.boolean().optional().describe("Default true"),
          })
        )
        .min(1)
        .max(30),
    },
  },
  {
    name: "create_content_draft",
    scope: "content:write",
    title: "Draft a post",
    description:
      "Saves a draft post for a client. It is never published from here: someone schedules or " +
      "publishes it in the portal. The caption is plain text; blank lines start new paragraphs. " +
      "Images are added in the portal." + TWO_STEP,
    input: {
      client: clientId,
      type: z.enum(CONTENT_TYPE_IDS).describe("From list_content_types"),
      title: z.string().min(1).max(255),
      caption: z.string().max(10000).optional(),
      date: date("The day it is planned for").optional(),
      time: time("The time it is planned for (needs a date)").optional(),
      link: z.string().max(500).optional().describe("An http(s) link shown with the post"),
    },
  },
  {
    name: "propose_meeting",
    scope: "calendar:write",
    title: "Ask a client for a meeting",
    description:
      "Sends a meeting request for one date and time. Like the portal's calendar, it goes to " +
      "every account at the client's company that you can reach; each is notified in the portal " +
      "and by email, and accepts or declines." + TWO_STEP,
    input: {
      client: clientId,
      date: date("The day, today or later"),
      time: time("The start time"),
      topic: z.string().min(1).max(200).describe("What the meeting is about - the client sees this"),
      notes: z.string().max(2000).optional(),
    },
  },
  {
    name: "respond_to_meeting",
    scope: "calendar:write",
    title: "Answer a client's meeting request",
    description: "Accepts or declines a meeting request a client sent. The client is notified." + TWO_STEP,
    input: {
      id: recordId("The meeting's id - from list_pending_approvals (waiting_on_you)"),
      decision: z.enum(["accept", "decline"]),
    },
  },
  {
    name: "post_project_update",
    scope: "projects:write",
    title: "Post a project update",
    description:
      "Adds an update to a project. Published (the default), the client sees it - from `date` if " +
      "one is given - and is notified once it is live. With publish false it is a draft only the " +
      "project team sees. The body is plain text; blank lines start new paragraphs." + TWO_STEP,
    input: {
      project: recordId("The project's id - from list_projects"),
      title: z.string().min(1).max(200),
      body: z.string().max(2000).optional(),
      date: date("When the client should see it (default: now)").optional(),
      time: time("Time shown with the update").optional(),
      link: z.string().max(500).optional().describe("An http(s) link shown with the update"),
      publish: z.boolean().optional().describe("Default true; false saves a draft"),
    },
  },
  {
    name: "update_task",
    scope: "projects:write",
    title: "Change a project update",
    description:
      "Changes a project update (a task). Give only the fields to change; \"\" clears date, time " +
      "or link. Publishing a draft makes it visible to the client once its date has come." + TWO_STEP,
    input: {
      id: recordId("The task's id - from get_project"),
      title: z.string().min(1).max(200).optional(),
      body: z.string().max(2000).optional().describe("Replaces the whole text"),
      date: z.union([date("New date"), z.literal("")]).optional(),
      time: z.union([time("New time"), z.literal("")]).optional(),
      link: z.string().max(500).optional(),
      status: z.enum(["draft", "published"]).optional(),
      is_complete: z.boolean().optional(),
    },
  },
];

/** True if this connection can make any confirmed change at all. */
export function canWrite(scopes) {
  return WRITE_TOOLS.some((t) => scopes.includes(t.scope));
}

export function registerWriteTools(server, { auth, requestId, scopes }) {
  for (const tool of WRITE_TOOLS) {
    if (!scopes.includes(tool.scope)) continue;
    server.registerTool(
      tool.name,
      {
        title: tool.title,
        description: tool.description,
        inputSchema: tool.input,
        outputSchema: S.preparedChange,
        annotations: PREPARE,
      },
      async (args) =>
        portalCall({
          auth,
          requestId,
          tool: tool.name,
          path: "changes.php",
          body: { action: "prepare", tool: tool.name, args },
          schema: z.object(S.preparedChange),
          lead: (d) =>
            "NOTHING HAS CHANGED YET. Show the person this summary, word for word, and ask whether to go ahead:\n\n" +
            `${d.summary}\n\n` +
            "Only if they clearly say yes, call confirm_change with this confirmation_token and this exact summary " +
            `(valid until ${d.expires_at}). If they want anything different, call ${tool.name} again with the new details.`,
        })
    );
  }

  if (canWrite(scopes)) {
    server.registerTool(
      "confirm_change",
      {
        title: "Make a confirmed change",
        description:
          "Step 2 of 2: makes a change prepared by one of the other tools (create_form_draft, " +
          "review_form, propose_meeting, ...). Call it ONLY after the person has seen that tool's " +
          "summary and said yes. Pass its confirmation_token and its summary exactly as returned. " +
          "The change is checked again first, and happens at most once - repeating the call is safe.",
        inputSchema: {
          confirmation_token: z.string().max(100).describe("From the prepare step"),
          summary: z.string().max(5000).describe("The prepare step's summary, exactly as returned"),
        },
        outputSchema: S.confirmedChange,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
      },
      async ({ confirmation_token, summary }) =>
        portalCall({
          auth,
          requestId,
          tool: "confirm_change",
          path: "changes.php",
          body: { action: "confirm", confirmation_token, summary },
          schema: z.object(S.confirmedChange),
          lead: (d) => (d.duplicate ? "This had already been done - nothing happened twice." : "Done."),
        })
    );
  }

  if (scopes.includes("notifications:write")) {
    server.registerTool(
      "mark_notifications_read",
      {
        title: "Mark notifications read",
        description: "Marks some of your own notifications read (by id, from list_my_notifications), or all of them.",
        inputSchema: {
          ids: z.array(z.number().int().positive()).min(1).max(100).optional(),
          all: z.boolean().optional().describe("true marks every notification read"),
        },
        outputSchema: S.markedRead,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
      },
      async ({ ids, all }) =>
        portalCall({
          auth,
          requestId,
          tool: "mark_notifications_read",
          path: "notifications.php",
          body: all ? { all: true } : { ids: ids ?? [] },
          schema: z.object(S.markedRead),
        })
    );
  }
}
