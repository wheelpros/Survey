// The staff write tools. Every change goes in two steps, through
// api/v1/changes.php:
//
//   1. a "prepare" tool - create_form_draft, update_form, review_form, ... - checks the
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
// Marking notifications read (mark_notifications_read for staff,
// mark_my_notifications_read for a client) is the one write that skips
// this: it only ever touches the person's own inbox.

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

// Images go by link: the portal downloads them on confirm and checks them as
// its own upload does (api/content-image-fetch.php). A tool call can't carry
// the file itself, so the description tells the assistant how to walk the
// person through getting a link.
const httpsLink = z.string().url().startsWith("https://", "must be an https:// link").max(2000);
const IMAGE_BY_LINK =
  "The portal downloads it when the change is confirmed. " +
  "An image attached to the chat can't be sent from here: the assistant never gets the file itself, only a link works. " +
  "When the person wants to add an image they only have as a file, explain this plainly and offer the way through: " +
  "(1) put the image in Google Drive, Dropbox, OneDrive or any storage that gives share links - if you have a connector " +
  "for one of them in this chat (Google Drive, Dropbox...), use it to find the file and get its link; otherwise ask them to " +
  "connect one in their AI app's connector settings, or to upload it there themselves; " +
  "(2) set its sharing to \"Anyone with the link\" - a private file can't be downloaded; " +
  "(3) give that link here. Google Drive and Dropbox share links work as they are; the portal fetches the file behind them.";

const postImages = z
  .array(httpsLink)
  .max(5)
  .describe("Up to 5 public https links to JPG, PNG, WEBP or GIF images, 3 MB each, in the order to show them. " + IMAGE_BY_LINK);

const imageLink = (what) => httpsLink.describe(what + " " + IMAGE_BY_LINK);

const adminId = recordId("The admin's id - from get_settings");
const adminPosition = z
  .string()
  .min(1)
  .max(100)
  .describe("Their job, e.g. SEO Specialist - shown in their sidebar and account popup instead of their name");
const adminRole = z
  .enum(["seo_admin", "super_admin", "account_manager"])
  .describe("seo_admin is shown as Admin. The owner role can't be given");

// What a post carries for the team working on it: never shown to the client.
const postTeamFields = {
  orientation: z.enum(["horizontal", "vertical"]).optional(),
  priority: z.enum(["low", "medium", "high"]).optional(),
  notes: z
    .string()
    .max(5000)
    .optional()
    .describe("Notes for the team on the post - a brief, a checklist. Plain text; staff only, never shown to the client"),
  team: z
    .array(recordId("A teammate's id"))
    .max(50)
    .optional()
    .describe(
      "Everyone to put on the post, from list_content_teammates (the client's team), leaving yourself out. " +
        "Replaces who is on it ([] takes everyone off); each person newly added is notified. " +
        "Only the post's author, the owner and account managers change it."
    ),
};

// What a phase (a project update) carries for the team working on it, the
// same as the portal's Edit Phase page: never shown to the client.
const phaseTeamFields = {
  phase: z.string().max(80).optional().describe("The stage it belongs to, e.g. Planning; \"\" clears it"),
  role: z.string().max(80).optional().describe("The role the phase calls for, e.g. UI Designer; \"\" clears it"),
  priority: z.enum(["low", "medium", "high"]).optional(),
  notes: z
    .string()
    .max(5000)
    .optional()
    .describe("Notes for the team - meeting notes, requirements, links. Markdown; staff only, never shown to the client"),
  team: z
    .array(recordId("A teammate's id"))
    .max(50)
    .optional()
    .describe(
      "Everyone to put on the phase, from list_phase_teammates (the client's team). Replaces who is on it " +
        "([] takes everyone off); each person newly added is notified."
    ),
  assignments: z
    .array(
      z.object({
        admin_id: recordId("The person's id - from list_phase_teammates"),
        work_note: z.string().max(2000).optional().describe("What they did, and any details"),
        status: z.enum(["todo", "in_progress", "done"]).optional().describe("Default todo"),
      })
    )
    .max(50)
    .optional()
    .describe(
      "Who worked on the phase, one row per person. Replaces the whole list ([] clears it); someone listed " +
        "again keeps their row. Each person newly added is notified."
    ),
};

const TWO_STEP =
  " Step 1 of 2: nothing changes yet. Show the person the summary this returns, " +
  "exactly, and call confirm_change only after they say yes.";

// Edits take only the fields to change, and the summary lists each change.
const EDIT = " Give only the fields to change.";

const formQuestions = z
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
  .max(50);

const inquiryFields = z
  .array(
    z.object({
      label: z.string().min(1).max(200),
      type: z.enum(["input", "textarea"]).optional().describe("input: up to 120 characters (default); textarea: up to 800"),
      required: z.boolean().optional().describe("Default true"),
    })
  )
  .min(1)
  .max(30);

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
      questions: formQuestions,
    },
  },
  {
    name: "update_form",
    scope: "forms:write",
    title: "Change a form",
    description:
      "Changes a form that its client hasn't answered yet (get_form shows it as it is). Questions, " +
      "if given, replace the whole list. Any change sends the form back to the review queue - a " +
      "form already released is taken back from the client until a reviewer approves it again." +
      EDIT + TWO_STEP,
    input: {
      id: recordId("The form's id - from list_forms"),
      title: z.string().min(1).max(255).optional(),
      description: z.string().max(5000).optional().describe("Replaces the whole text"),
      questions: formQuestions.optional().describe("The full new list of questions"),
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
      fields: inquiryFields,
    },
  },
  {
    name: "update_inquiry",
    scope: "inquiries:write",
    title: "Change a consultation inquiry",
    description:
      "Changes a consultation inquiry (get_inquiry_form shows it as it is). Fields, if given, " +
      "replace the whole list. Once anyone has answered it, its title, intro and questions are " +
      "locked; its reference can still change and it can still be closed. A new title changes " +
      "its public link. It can be closed here but only opened on the portal's Inquiries page." +
      EDIT + TWO_STEP,
    input: {
      id: recordId("The inquiry's id - from list_inquiries"),
      title: z.string().min(1).max(200).optional(),
      intro: z.string().max(2000).optional().describe("Replaces the whole text"),
      fields: inquiryFields.optional().describe("The full new list of questions"),
      reference: z.string().max(100).optional().describe("The office's own reference; \"\" clears it"),
      close: z.literal(true).optional().describe("true closes it to new answers"),
    },
  },
  {
    name: "create_content_draft",
    scope: "content:write",
    title: "Draft a post",
    description:
      "Saves a draft post for a client; publish_content schedules or publishes it afterwards. " +
      "The caption is plain text; blank lines start new paragraphs. Images are given as links: " +
      "the portal downloads them when the change is confirmed. orientation, priority, notes and " +
      "team (from list_content_teammates) are set here too." + TWO_STEP,
    input: {
      client: clientId,
      type: z.enum(CONTENT_TYPE_IDS).describe("From list_content_types"),
      title: z.string().min(1).max(255),
      caption: z.string().max(10000).optional(),
      date: date("The day it is planned for").optional(),
      time: time("The time it is planned for (needs a date)").optional(),
      link: z.string().max(500).optional().describe("An http(s) link shown with the post"),
      images: postImages.optional(),
      ...postTeamFields,
    },
  },
  {
    name: "update_content_draft",
    scope: "content:write",
    title: "Change a draft post",
    description:
      "Changes a draft post (get_content shows it as it is): its text, date, link and images, and " +
      "for the team its orientation, priority, notes and who is on it. Only drafts: a scheduled or " +
      "published post is edited in the portal. To schedule or publish, use publish_content. The " +
      "caption is plain text and replaces the whole caption; \"\" clears date, time, link or notes. " +
      "images replaces all of the post's images ([] removes them)." + EDIT + TWO_STEP,
    input: {
      id: recordId("The post's id - from list_content"),
      type: z.enum(CONTENT_TYPE_IDS).optional().describe("From list_content_types"),
      title: z.string().min(1).max(255).optional(),
      caption: z.string().max(10000).optional(),
      date: z.union([date("The day it is planned for"), z.literal("")]).optional(),
      time: z.union([time("The time it is planned for (needs a date)"), z.literal("")]).optional(),
      link: z.string().max(500).optional().describe("An http(s) link shown with the post"),
      images: postImages.optional(),
      ...postTeamFields,
    },
  },
  {
    name: "publish_content",
    scope: "content:write",
    title: "Schedule or publish a post",
    description:
      "Schedules a draft post to go live at a date and time, or publishes it now - the owner and " +
      "account managers only, as in the portal. A scheduled post goes live on its own; a time " +
      "already passed goes live straight away. The client is notified when it goes live. A post " +
      "that is out is changed or taken down in the portal." + TWO_STEP,
    input: {
      id: recordId("The draft post's id - from list_content"),
      date: date("The day it goes live (with time)").optional(),
      time: time("The time it goes live, portal local time (with date)").optional(),
      publish_now: z.literal(true).optional().describe("true publishes it now, instead of a date and time"),
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
      "project team sees. The body is plain text; blank lines start new paragraphs. In the portal " +
      "these are the project's phases: phase, role, priority, notes, team and assignments are set " +
      "here too (people from list_phase_teammates)." + TWO_STEP,
    input: {
      project: recordId("The project's id - from list_projects"),
      title: z.string().min(1).max(200),
      body: z.string().max(2000).optional(),
      date: date("When the client should see it (default: now)").optional(),
      time: time("Time shown with the update").optional(),
      link: z.string().max(500).optional().describe("An http(s) link shown with the update"),
      publish: z.boolean().optional().describe("Default true; false saves a draft"),
      ...phaseTeamFields,
    },
  },
  {
    name: "update_task",
    scope: "projects:write",
    title: "Change a project update",
    description:
      "Changes a project update - a phase in the portal. Give only the fields to change; \"\" clears " +
      "date, time, link, phase, role or notes. Publishing a draft makes it visible to the client once " +
      "its date has come. team and assignments replace their whole list." + TWO_STEP,
    input: {
      id: recordId("The task's id - from get_project"),
      title: z.string().min(1).max(200).optional(),
      body: z.string().max(2000).optional().describe("Replaces the whole text"),
      date: z.union([date("New date"), z.literal("")]).optional(),
      time: z.union([time("New time"), z.literal("")]).optional(),
      link: z.string().max(500).optional(),
      status: z.enum(["draft", "published"]).optional(),
      is_complete: z.boolean().optional(),
      ...phaseTeamFields,
    },
  },
  {
    name: "set_my_phase_status",
    scope: "projects:write",
    title: "Set my status on a phase",
    description:
      "Moves your own part of a phase: status is your assignment (todo, in_progress, done), done ticks " +
      "your teammate box. Only your own row changes - list_my_phases shows where you stand." + TWO_STEP,
    input: {
      id: recordId("The phase's id - from list_my_phases or get_project"),
      status: z.enum(["todo", "in_progress", "done"]).optional(),
      done: z.boolean().optional(),
    },
  },

  // ── Settings (the owner's) ────────────────────────────────────────────
  {
    name: "update_website_address",
    scope: "settings:write",
    title: "Change the website address",
    description:
      "Sets the public website address the logo links to on every public page, e.g. wzone.london. " +
      "\"\" clears it, so the logo is no longer a link." + TWO_STEP,
    input: { address: z.string().max(500).describe("The address as it should be shown; https:// is added when missing") },
  },
  {
    name: "set_logo",
    scope: "settings:write",
    title: "Replace the logo",
    description:
      "Replaces the portal's logo, shown on every page, with a PNG or JPG image at a link." + TWO_STEP,
    input: { image: imageLink("The new logo: a public https link to a PNG or JPG image, 3 MB at most.") },
  },
  {
    name: "add_login_slide",
    scope: "settings:write",
    title: "Add a login page slide",
    description:
      "Adds a slide to the slideshow on the sign-in page, after the others. get_settings gives how many " +
      "there can be and the smallest image size." + TWO_STEP,
    input: {
      image: imageLink("A public https link to a JPG, PNG, WEBP or GIF image, 3 MB at most, at least 600x800 px."),
      title: z.string().max(120).optional(),
      subtitle: z.string().max(255).optional(),
    },
  },
  {
    name: "update_login_slide",
    scope: "settings:write",
    title: "Change a login slide's text",
    description: "Changes a sign-in page slide's title and subtitle. \"\" clears one." + EDIT + TWO_STEP,
    input: {
      slide: recordId("The slide's id - from get_settings"),
      title: z.string().max(120).optional(),
      subtitle: z.string().max(255).optional(),
    },
  },
  {
    name: "reorder_login_slides",
    scope: "settings:write",
    title: "Reorder the login slides",
    description: "Puts the sign-in page slides in a new order. List every slide's id, first to last." + TWO_STEP,
    input: { order: z.array(recordId("A slide's id")).min(1).max(20).describe("Every slide id, in the new order") },
  },
  {
    name: "remove_login_slide",
    scope: "settings:write",
    title: "Remove a login slide",
    description: "Removes a slide from the sign-in page slideshow, and its image. It can't be undone." + TWO_STEP,
    input: { slide: recordId("The slide's id - from get_settings") },
  },

  // ── The admin team (the owner's) ──────────────────────────────────────
  {
    name: "add_admin",
    scope: "admins:write",
    title: "Add an admin",
    description:
      "Adds someone to the admin team. No password is set here: they are emailed a link to choose their own, " +
      "good for 3 days. Roles: seo_admin (shown as Admin), super_admin, account_manager. The position " +
      "(their job, e.g. SEO Specialist) is required: it is shown in their sidebar and account popup instead of their name." + TWO_STEP,
    input: {
      name: z.string().min(1).max(100),
      position: adminPosition,
      email: z.string().email().max(190),
      role: adminRole,
    },
  },
  {
    name: "set_admin_position",
    scope: "admins:write",
    title: "Set an admin's position",
    description:
      "Sets or changes an admin's position (their job, e.g. SEO Specialist), shown in their sidebar and account " +
      "popup instead of their name. Admins added before positions existed show their name until one is set. " +
      "Nobody is signed out." + TWO_STEP,
    input: { admin: adminId, position: adminPosition },
  },
  {
    name: "change_admin_role",
    scope: "admins:write",
    title: "Change an admin's role",
    description:
      "Changes an admin's role. They are signed out and pick up the new role when they next sign in. " +
      "The owner's role, and your own, can't be changed." + TWO_STEP,
    input: { admin: adminId, role: adminRole },
  },
  {
    name: "set_admin_active",
    scope: "admins:write",
    title: "Switch an admin on or off",
    description:
      "Deactivating stops an admin signing in; nothing of theirs is removed, and activating them again restores " +
      "everything as it was. You can't deactivate yourself." + TWO_STEP,
    input: { admin: adminId, active: z.boolean().describe("true: they can sign in; false: they can't") },
  },
  {
    name: "assign_admins_to_manager",
    scope: "admins:write",
    title: "Assign admins to a manager",
    description:
      "Sets exactly who a super admin or account manager manages, replacing who they have now ([] clears it). " +
      "A super admin can manage Admins (seo_admin); an account manager, Admins and super admins. " +
      "Someone who had another manager moves to this one." + TWO_STEP,
    input: {
      manager: recordId("The super admin's or account manager's id - from get_settings"),
      admins: z.array(adminId).max(200).describe("Every admin they should manage"),
    },
  },

  // ── A client's own account (self:write) ───────────────────────────────
  {
    name: "request_meeting",
    scope: "self:write",
    title: "Ask your team for a meeting",
    description:
      "Asks your team for a meeting at one date and time. They are told in the portal " +
      "and by email, and accept or decline it." + TWO_STEP,
    input: {
      date: date("The day, today or later"),
      time: time("The start time"),
      topic: z.string().min(1).max(200).describe("What you'd like to talk about"),
      notes: z.string().max(2000).optional(),
    },
  },
  {
    name: "respond_to_my_meeting",
    scope: "self:write",
    title: "Answer a meeting request",
    description:
      "Accepts or declines a meeting your team asked you for (my_overview lists them under " +
      "meetings_waiting_on_you). Whoever asked is told." + TWO_STEP,
    input: {
      id: recordId("The meeting's id"),
      decision: z.enum(["accept", "decline"]),
    },
  },
  {
    name: "submit_my_form",
    scope: "self:write",
    title: "Send my answers to a form",
    description:
      "Sends your answers to a form your team sent you (get_my_form shows its questions). Every " +
      "question needs an answer: text for input and textarea; for a checkbox question with " +
      "options, a list of the options ticked; for a single tick box, true. Forms with a file " +
      "question have to be finished on the portal page. Once sent, answers can't be changed." +
      TWO_STEP,
    input: {
      id: recordId("The form's id"),
      answers: z
        .array(
          z.object({
            question_id: z.number().int().positive(),
            value: z.union([z.string().max(10000), z.array(z.string().max(200)).max(50), z.boolean()]),
          })
        )
        .min(1)
        .max(100),
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
          "Step 2 of 2: makes a change prepared by one of the other tools (" +
          WRITE_TOOLS.filter((t) => scopes.includes(t.scope)).map((t) => t.name).join(", ") +
          "). Call it ONLY after the person has seen that tool's summary and said yes. Pass its " +
          "confirmation_token and its summary exactly as returned. The change is checked again " +
          "first, and happens at most once - repeating the call is safe.",
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

  // Marking read: staff through notifications.php, a client through
  // me/notifications.php - one tool each, never both.
  const markRead = scopes.includes("notifications:write")
    ? { name: "mark_notifications_read", path: "notifications.php" }
    : scopes.includes("self:write")
      ? { name: "mark_my_notifications_read", path: "me/notifications.php" }
      : null;

  if (markRead) {
    server.registerTool(
      markRead.name,
      {
        title: "Mark notifications read",
        description: "Marks some of your own notifications read (by id), or all of them.",
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
          tool: markRead.name,
          path: markRead.path,
          body: all ? { all: true } : { ids: ids ?? [] },
          schema: z.object(S.markedRead),
        })
    );
  }
}
