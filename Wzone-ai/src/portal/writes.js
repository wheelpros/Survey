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
// Marking read (mark_notifications_read, mark_chat_read and
// mark_client_chat_read for staff, mark_my_notifications_read and
// mark_my_messages_read for a client) skips this: it only ever moves the
// person's own read markers.

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
const permissionChanges = z
  .record(z.string().regex(/^[a-z_]+\.[a-z_]+$/, "must be a permission key like content.create"),
    z.union([z.boolean(), z.literal("default")]))
  .refine((c) => Object.keys(c).length > 0 && Object.keys(c).length <= 40, "change 1 to 40 permissions")
  .describe("Permission key (from get_permissions) => true, false or \"default\"");
const adminPosition = z
  .string()
  .min(1)
  .max(100)
  .describe("Their job, e.g. SEO Specialist - shown in their sidebar and account popup instead of their name");
const adminRole = z
  .enum(["seo_admin", "super_admin", "account_manager"])
  .describe("seo_admin is shown as Admin. The Director role can't be given");

// What a post carries for the team working on it: never shown to the client.
const postTeamFields = {
  orientation: z.enum(["horizontal", "vertical"]).optional(),
  priority: z.enum(["low", "medium", "high"]).optional(),
  due_date: z
    .union([date("When the team should have it done"), z.literal("")])
    .optional()
    .describe("When the team should have it done (\"\" clears it). Staff only; the dashboard's Due column shows it, else the post's date"),
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
        "Only the post's author, the Director and account managers change it."
    ),
  assignments: z
    .array(
      z.object({
        admin_id: recordId("A teammate's id - one of the post's team"),
        work_note: z.string().max(2000).optional().describe("What they do on the post, and any details"),
      })
    )
    .max(50)
    .optional()
    .describe(
      "The Assigned table: what each teammate does on the post. Each admin_id must be on the team (after this " +
        "change). No status here - each teammate sets their own with set_my_post_status."
    ),
};

// What a phase (a project update) carries for the team working on it, the
// same as the portal's Edit Phase page: never shown to the client.
const phaseTeamFields = {
  priority: z.enum(["low", "medium", "high"]).optional(),
  due_date: z
    .union([date("When the team should have it done"), z.literal("")])
    .optional()
    .describe("When the team should have it done (\"\" clears it). Staff only; the dashboard's Due column shows it, else the phase's date"),
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
      "Everyone to put on the phase, from list_phase_teammates (the people who can open the project). Replaces who is on it " +
        "([] takes everyone off); each person newly added is notified."
    ),
  assignments: z
    .array(
      z.object({
        admin_id: recordId("A teammate's id - one of the phase's team"),
        work_note: z.string().max(2000).optional().describe("What they do, and any details"),
      })
    )
    .max(50)
    .optional()
    .describe(
      "The Assigned table: which teammates work on the phase, one row per person. Each admin_id must be on the " +
        "team (after this change) - add them to team first. Replaces the whole list ([] clears it); someone " +
        "listed again keeps their row and their status. No status here: each person sets their own with " +
        "set_my_phase_status, and the phase completes by itself once everyone assigned is done. Each person " +
        "newly added is notified."
    ),
  images: z
    .array(httpsLink)
    .max(5)
    .optional()
    .describe(
      "The phase gallery, beside its cover: up to 5 public https links to JPG, PNG, WEBP or GIF images, 3 MB " +
        "each, shown on the phase page to the team only. Replaces the gallery; [] clears it. " + IMAGE_BY_LINK
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
      "the portal downloads them when the change is confirmed. orientation, priority, due_date, notes and " +
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
      "for the team its orientation, priority, due_date, notes and who is on it. Only drafts: a scheduled or " +
      "published post is edited in the portal. To schedule or publish, use publish_content. The " +
      "caption is plain text and replaces the whole caption; \"\" clears date, time, link, due_date or notes. " +
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
      "Schedules a draft post to go live at a date and time, or publishes it now - only for whoever may publish " +
      "(the Director and account managers, unless the Director changed it). A scheduled post goes live on its own; a time " +
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
      "Sends a meeting request for one date and time. Like the portal's Appointments page, it goes to " +
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
    title: "Answer a meeting request",
    description:
      "Accepts or declines a meeting request a client sent, or a time someone picked from your booking link. " +
      "They are told; accepting sends them a calendar invite." + TWO_STEP,
    input: {
      id: recordId("The meeting's id - from list_pending_approvals (waiting_on_you)"),
      decision: z.enum(["accept", "decline"]),
    },
  },
  {
    name: "cancel_meeting",
    scope: "calendar:write",
    title: "Cancel a meeting",
    description:
      "Calls off a confirmed meeting, or withdraws a meeting request still waiting on an answer. " +
      "The client or guest is told by email (a client in the portal too), and a confirmed meeting's " +
      "calendar invite is cancelled." + TWO_STEP,
    input: {
      id: recordId("The meeting's id - from list_meetings or list_pending_approvals"),
      reason: z.string().max(500).optional().describe("Sent to them as written"),
    },
  },
  {
    name: "add_meeting",
    scope: "calendar:write",
    title: "Add a confirmed meeting",
    description:
      "Puts a meeting straight on the calendar, confirmed, with a client (every account at their company) " +
      "or a guest. They get an email with a calendar invite. Your meeting length and time zone apply " +
      "(get_open_times shows them). A time that clashes with another meeting or is outside your hours is " +
      "refused unless allow_clash is true - say so to the person first." + TWO_STEP,
    input: {
      client: clientId.optional().describe("The client - from search_clients. Or give guest_name and guest_email"),
      guest_name: z.string().min(1).max(150).optional().describe("A guest without a portal account: their name"),
      guest_email: z.string().email().max(190).optional().describe("The guest's email - everything reaches them there"),
      date: date("The day"),
      time: time("The start time, in your time zone"),
      topic: z.string().min(1).max(200).describe("What the meeting is about - they see this"),
      notes: z.string().max(2000).optional(),
      allow_clash: z.boolean().optional().describe("true adds it even if it clashes or is outside your hours"),
    },
  },
  {
    name: "send_booking_request",
    scope: "calendar:write",
    title: "Send a booking link",
    description:
      "Sends a client (everyone on their company's accounts) or a guest a link to pick one of your open times " +
      "in the next window_days days. When they pick, it waits in list_pending_approvals for you to confirm " +
      "(respond_to_meeting) or decline; confirming sends the calendar invite. For a guest, the result " +
      "includes the link - it is a credential, share it only with them." + TWO_STEP,
    input: {
      client: clientId.optional().describe("The client - from search_clients. Or give guest_name and guest_email"),
      guest_name: z.string().min(1).max(150).optional().describe("A guest without a portal account: their name"),
      guest_email: z.string().email().max(190).optional().describe("The guest's email - everything reaches them there"),
      topic: z.string().min(1).max(200).describe("What the meeting is about - they see this"),
      notes: z.string().max(2000).optional(),
      window_days: z.union([z.literal(7), z.literal(14), z.literal(30)]).optional().describe("How long they have to pick (default 14)"),
    },
  },
  {
    name: "withdraw_booking_request",
    scope: "calendar:write",
    title: "Withdraw a booking link",
    description: "Withdraws a booking request nobody has picked from: its links stop working. Nobody is emailed." + TWO_STEP,
    input: { id: recordId("The booking request's id - from list_booking_requests") },
  },
  {
    name: "resend_booking_request",
    scope: "calendar:write",
    title: "Send a booking link again",
    description: "Emails an open booking request again, with new links; the old ones stop working." + TWO_STEP,
    input: { id: recordId("The booking request's id - from list_booking_requests") },
  },
  // ── Messages and announcements ───────────────────────────────────────
  {
    name: "send_message",
    scope: "messages:write",
    title: "Messages: send",
    description:
      "Sends another admin a message from you on the portal's Messages page - a new chat or a reply in one " +
      "(read it first with read_chat). Messages are private to the two of you, and Tracking doesn't record them. " +
      "Write what the person asked you to say - it goes out under their name." + TWO_STEP,
    input: {
      to: recordId("The admin's id - from list_messages or list_message_people"),
      body: z.string().min(1).max(2000).describe("The message, as it will appear"),
    },
  },
  {
    name: "send_client_message",
    scope: "messages:write",
    title: "Messages: write to a client",
    description:
      "Sends a message from you in a client's chat on the portal's Messages page (read it first with " +
      "read_client_chat). Only for clients you answer (list_client_chats, access 'team'); the Director can't write " +
      "where the client has an account manager. Everyone on the client's account sees it, " +
      "and so do its other account managers; they may be emailed if it stays unread. Write what the person asked you " +
      "to say - it goes out under their name." + TWO_STEP,
    input: {
      client: recordId("The client's id - from list_client_chats"),
      body: z.string().min(1).max(2000).describe("The message, as it will appear"),
    },
  },
  {
    name: "send_announcement",
    scope: "announcements:write",
    title: "Send an announcement",
    description:
      "Sends an announcement to the team (admins) or to clients: everyone in that audience, or the people " +
      "you name. Each gets a notification and can open it in full. No image - attach one in the portal." + TWO_STEP,
    input: {
      audience: z.enum(["team", "client"]).describe("team: admins; client: the clients' portal accounts"),
      scope: z.enum(["all", "specific"]).optional().describe("all (default): everyone in the audience; specific: only recipients"),
      recipients: z
        .array(z.number().int().positive())
        .min(1)
        .max(500)
        .optional()
        .describe("With scope specific: admin ids (from get_settings) or client ids (from search_clients)"),
      title: z.string().min(1).max(200),
      description: z.string().max(5000).optional(),
      event_date: date("The date it is about, if any").optional(),
    },
  },
  {
    name: "send_team_form",
    scope: "team_forms:write",
    title: "Send a form to the team",
    description:
      "Sends a form to other admins: the whole team, everyone in some roles, specific people (admin ids from " +
      "get_settings), or roles plus people. Each recipient gets a notification, fills it in once in the portal and " +
      "sends it back; you, the Director and anyone allowed to see team form answers can read them with get_team_form. " +
      "You're never sent your own form." + TWO_STEP,
    input: {
      scope: z.enum(["all", "roles", "specific"]).optional().describe("all (default): every active admin; roles/specific: only roles and/or recipients"),
      roles: z
        .array(z.enum(["owner", "super_admin", "account_manager", "seo_admin"]))
        .max(4)
        .optional()
        .describe("owner is the Director, seo_admin is Admin"),
      recipients: z.array(z.number().int().positive()).max(500).optional().describe("Admin ids, added to anyone in roles"),
      title: z.string().min(1).max(200),
      description: z.string().max(5000).optional().describe("Shown above the questions"),
      due_date: date("When answers are due, if there is a date").optional(),
      questions: formQuestions,
    },
  },
  {
    name: "answer_team_form",
    scope: "team_forms:answer",
    title: "Answer a team form",
    description:
      "Sends your answers to a team form someone sent you (get_team_form shows its questions). Once only, while it's " +
      "open. A form with a required file upload can only be answered in the portal. Write what the person wants to answer - " +
      "it goes back under their name." + TWO_STEP,
    input: {
      id: recordId("The team form's id - from list_team_forms"),
      answers: z
        .array(
          z.object({
            question_id: recordId("The question's id from get_team_form"),
            answer: z.union([z.string().max(10000), z.array(z.string().max(100)).max(20)]).describe("Text, or for a checkbox question the options ticked"),
          })
        )
        .max(100),
    },
  },
  {
    name: "close_team_form",
    scope: "team_forms:write",
    title: "Close or reopen a team form",
    description: "Closes a team form you sent so it takes no more answers, or reopens it. The Director can do this for any." + TWO_STEP,
    input: {
      id: recordId("The team form's id"),
      reopen: z.boolean().optional().describe("true to reopen a closed form"),
    },
  },
  {
    name: "plan_my_activity_report",
    scope: "activity_reports:write",
    title: "Create my own activity report",
    description:
      "Creates a new activity report for yourself, dated today, whatever reports you already have: the activities, " +
      "and who reviews it (one or more ids from list_activity_report_reviewers; any one of them completes the review). " +
      "Each activity can already carry its status, outcome and count. The reviewers are told as soon as it's saved " +
      "and can review Done activities straight away. Needs \"Plan their own activity report\"." + TWO_STEP,
    input: {
      reviewers: z.array(z.number().int().positive()).min(1).max(20).describe("Admin ids from list_activity_report_reviewers"),
      activities: z
        .array(
          z.object({
            title: z.string().min(1).max(200),
            description: z.string().max(2000).optional(),
            count_unit: z.string().max(30).optional().describe("Something to count, like calls or CVs"),
            status: z.enum(["todo", "in_progress", "follow_up", "done"]).optional().describe("Where it stands (default todo)"),
            outcome: z.string().max(5000).optional().describe("What came of it so far"),
            count: z.number().int().min(0).optional().describe("How many, for an activity with a count_unit"),
          })
        )
        .min(1)
        .max(30),
    },
  },
  {
    name: "add_report_activity",
    scope: "activity_reports:write",
    title: "Add an activity to my activity report",
    description:
      "Adds an activity of your own to an open report someone else planned for you (can_add_own_activities on " +
      "get_activity_report). It's marked as added by you, and the planner's Edit plan leaves it alone." + TWO_STEP,
    input: {
      report: recordId("The report's id - from list_my_activity_reports"),
      title: z.string().min(1).max(200),
      description: z.string().max(2000).optional(),
      count_unit: z.string().max(30).optional().describe("Something to count, like calls or CVs"),
    },
  },
  {
    name: "remove_report_activity",
    scope: "activity_reports:write",
    title: "Remove an activity I added",
    description:
      "Takes back an activity you added (added_by_author) to an open report, before any work on it. Planned " +
      "activities can't be removed this way." + TWO_STEP,
    input: {
      report: recordId("The report's id"),
      activity: recordId("The activity's id - from get_activity_report"),
    },
  },
  {
    name: "save_end_of_day",
    scope: "activity_reports:write",
    title: "Write the end of day on my activity report",
    description:
      "Saves the end-of-day note on one of your open reports, from any day, without sending it: outstanding work, tomorrow's " +
      "priorities, and challenges or assistance required. Give only the parts to change; the rest stays. " +
      "challenge_priority is required with challenges (high = you need help). close_activity_report can also " +
      "take them." + TWO_STEP,
    input: {
      report: recordId("The report's id"),
      outstanding: z.string().max(5000).optional().describe("Work left, and why"),
      priorities: z.string().max(5000).optional().describe("Tomorrow's priorities"),
      challenges: z.string().max(5000).optional().describe("Challenges or assistance required"),
      challenge_priority: z.enum(["low", "medium", "high"]).optional(),
    },
  },
  {
    name: "update_report_activity",
    scope: "activity_reports:write",
    title: "Update an activity on my activity report",
    description:
      "Sets one activity on your own activity report: its status, outcome and/or count - only what you give changes. " +
      "Until you send the report, whatever its day, any activity not reviewed yet; once it's sent, only the ones " +
      "sent back to you. Saving it as done lets your reviewers review it." + TWO_STEP,
    input: {
      report: recordId("The report's id - from list_my_activity_reports"),
      activity: recordId("The activity's id - from get_activity_report"),
      status: z.enum(["todo", "in_progress", "follow_up", "done"]).optional(),
      outcome: z.string().max(5000).optional().describe("What came of it, and what happens next"),
      count: z.number().int().min(0).optional().describe("How many, for an activity with a count_unit"),
    },
  },
  {
    name: "close_activity_report",
    scope: "activity_reports:write",
    title: "Send my activity report",
    description:
      "Sends one of your open reports, from any day, to its reviewers (any one of them reviews it). After that it's locked, " +
      "except activities sent back. The end-of-day note goes with it: give only the parts you have; a parameter " +
      "left out keeps what's saved. challenge_priority is required with challenges (high = they need help)." + TWO_STEP,
    input: {
      id: recordId("The report's id"),
      outstanding: z.string().max(5000).optional().describe("Work left, and why"),
      priorities: z.string().max(5000).optional().describe("Tomorrow's priorities"),
      challenges: z.string().max(5000).optional().describe("Challenges or assistance required"),
      challenge_priority: z.enum(["low", "medium", "high"]).optional(),
    },
  },
  {
    name: "review_report_activity",
    scope: "activity_reports:review",
    title: "Review an activity on an activity report",
    description:
      "For any of the report's reviewers, the Director, or whoever reviews the author's team: marks a Done activity as reviewed (feedback optional; it locks), or sends it back " +
      "with feedback saying what to change (it reopens for its author). Either way they're notified." + TWO_STEP,
    input: {
      activity: recordId("The activity's id - from get_activity_report"),
      decision: z.enum(["approved", "changes"]),
      feedback: z.string().max(2000).optional().describe("Required to send it back"),
    },
  },
  {
    name: "review_activity_report",
    scope: "activity_reports:review",
    title: "Mark an activity report as reviewed",
    description:
      "For the report's reviewers, once it's sent - the first one completes it: marks the whole report reviewed, approving any Done activities " +
      "not reviewed yet, with an optional note. Refused while an activity waits on changes." + TWO_STEP,
    input: {
      id: recordId("The report's id"),
      note: z.string().max(2000).optional(),
    },
  },
  {
    name: "post_project_update",
    scope: "projects:write",
    title: "Post a project update",
    description:
      "Adds an update to a project - a phase in the portal. date is optional: a dated phase shows on the " +
      "calendars on its date. Published (the default), the client sees it from that date and is notified " +
      "once it is live. With publish false it is a draft only the project team sees. The body is plain text; " +
      "blank lines start new paragraphs. Priority, notes, team, assignments and gallery images " +
      "are set here too (people from list_phase_teammates; assignments only from the team)." + TWO_STEP,
    input: {
      project: recordId("The project's id - from list_projects"),
      title: z.string().min(1).max(200),
      body: z.string().max(2000).optional(),
      date: date("The phase's date: it shows on the calendars on this day, and a published phase reaches the client then").optional(),
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
      "date, time, link, notes or due_date. Publishing a draft makes it visible to the client once " +
      "its date has come. team, assignments and images replace their whole list. A phase is never marked " +
      "complete by hand: it completes once everyone assigned is done." + TWO_STEP,
    input: {
      id: recordId("The task's id - from get_project"),
      title: z.string().min(1).max(200).optional(),
      body: z.string().max(2000).optional().describe("Replaces the whole text"),
      date: z.union([date("The new date"), z.literal("")]).optional(),
      time: z.union([time("New time"), z.literal("")]).optional(),
      link: z.string().max(500).optional(),
      status: z.enum(["draft", "published"]).optional(),
      ...phaseTeamFields,
    },
  },
  {
    name: "set_my_post_status",
    scope: "content:write",
    title: "Set my status on a post",
    description:
      "Moves your own row in a content post's Assigned table: todo, in_progress or done. Only your own row " +
      "changes, whatever your role. get_dashboard shows the posts you are on." + TWO_STEP,
    input: {
      id: recordId("The post's id - from get_dashboard or list_content"),
      status: z.enum(["todo", "in_progress", "done"]),
    },
  },
  {
    name: "set_my_post_done",
    scope: "content:write",
    title: "Tick my box on a post",
    description:
      "The older form of set_my_post_status: true sets your status on the post to done, false back to " +
      "todo. Only your own row changes. get_dashboard shows the posts you are on." + TWO_STEP,
    input: {
      id: recordId("The post's id - from get_dashboard or list_content"),
      done: z.boolean().describe("true ticks it, false unticks it"),
    },
  },
  {
    name: "set_my_phase_status",
    scope: "projects:write",
    title: "Set my status on a phase",
    description:
      "Moves your own row in a phase's Assigned table: todo, in_progress or done. Only your own row " +
      "changes, whatever your role, and only someone assigned has one. The phase completes by itself once " +
      "everyone assigned is done. list_my_phases shows where you stand." + TWO_STEP,
    input: {
      id: recordId("The phase's id - from list_my_phases or get_project"),
      status: z.enum(["todo", "in_progress", "done"]),
    },
  },
  {
    name: "delete_task",
    scope: "projects:write",
    title: "Delete a phase",
    description:
      "Deletes a phase (a project update) with its team, assignments, images and attached files; the client " +
      "stops seeing it. Only whoever may edit a teammate's update, or the phase's creator, can - get_project's " +
      "can_delete says. This cannot be undone, so say what goes with it before confirming." + TWO_STEP,
    input: { id: recordId("The phase's id - from get_project") },
  },

  // ── Settings (the Director's) ─────────────────────────────────────────
  {
    name: "update_my_profile",
    scope: "settings:write",
    title: "Change my Director card",
    description:
      "The Director's own name and/or position, shown on the card at the top of Settings. Nothing else " +
      "changes here: the email is what they sign in with and the role is fixed. Director only." + TWO_STEP,
    input: {
      name: z.string().min(1).max(100).optional(),
      position: z.string().min(1).max(100).optional().describe("Their job, e.g. Managing Director"),
    },
  },
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

  // ── The admin team (the Director's) ──────────────────────────────────────
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
    name: "update_admin",
    scope: "admins:write",
    title: "Edit an admin",
    description:
      "Changes an admin's name, email, position and/or role in one go - what the Edit popup on Settings " +
      "does. Give only what changes. The email must not belong to another admin, and someone without a " +
      "position needs one. A role change signs them out; the rest doesn't. The Director's account can't be " +
      "edited here (update_my_profile is theirs). For the photo, use set_admin_photo." + TWO_STEP,
    input: {
      admin: adminId,
      name: z.string().min(1).max(100).optional(),
      email: z.string().email().max(190).optional(),
      position: adminPosition.optional(),
      role: adminRole.optional(),
    },
  },
  {
    name: "set_admin_photo",
    scope: "admins:write",
    title: "Set an admin's photo",
    description:
      "Sets or removes an admin's photo, shown in the admin list and their sidebar. Give a public https link " +
      "to a JPG, PNG or WEBP image (3 MB at most), or an empty string to remove it. Nobody is signed out. " +
      IMAGE_BY_LINK + TWO_STEP,
    input: {
      admin: adminId,
      image: z.union([httpsLink, z.literal("")]).describe("The photo's https link, or an empty string to remove it"),
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
      "The Director's role, and your own, can't be changed." + TWO_STEP,
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

  // ── Permissions (the Director's) ──────────────────────────────────────
  {
    name: "set_role_permissions",
    scope: "admins:write",
    title: "Change what a role can do",
    description:
      "Changes permissions for everyone in a role - keys from get_permissions. true allows, false denies, " +
      "\"default\" goes back to the portal's default. A person's own exception (set_admin_permissions) still " +
      "beats the role. Director-only permissions (director_only in get_permissions) can't go to a role - " +
      "give them to one admin with set_admin_permissions. Nobody is signed out." + TWO_STEP,
    input: { role: adminRole, changes: permissionChanges },
  },
  {
    name: "set_admin_permissions",
    scope: "admins:write",
    title: "Make an exception for one admin",
    description:
      "Gives or takes a permission from one admin, whatever their role gets - keys from get_permissions. " +
      "true allows, false denies, \"default\" removes the exception so their role decides again. Changing " +
      "their role later clears their exceptions. Director-only permissions (director_only in get_permissions) can " +
      "be allowed here for this one admin; no role ever has them. Not for the Director, who holds everything." + TWO_STEP,
    input: { admin: adminId, changes: permissionChanges },
  },

  // ── A client's own account (self:write) ───────────────────────────────
  {
    name: "send_my_message",
    scope: "self:write",
    title: "Message my account manager",
    description:
      "Sends your account manager a message from you, in your account's chat on the portal's Messages page. " +
      "Everyone on your account sees it. Until an account manager is assigned it goes to the Director, who answers " +
      "in the meantime (my_messages says so). Write what the person asked you to say - it goes out under their name." +
      TWO_STEP,
    input: { body: z.string().min(1).max(2000).describe("The message, as it will appear") },
  },
  // A client's team (self:write, account owner only)
  {
    name: "invite_teammate",
    scope: "self:write",
    title: "Invite a teammate",
    description:
      "Emails someone a link to join your account, up to 4 teammates counting pending invites. " +
      "They then see everything on the account. Only the account owner can do this." + TWO_STEP,
    input: { email: z.string().email().max(190).describe("Their email address") },
  },
  {
    name: "resend_team_invite",
    scope: "self:write",
    title: "Resend a team invite",
    description: "Sends a pending invite again with a new link (my_team lists them). The old link stops working." + TWO_STEP,
    input: { id: recordId("The invite's id") },
  },
  {
    name: "revoke_team_invite",
    scope: "self:write",
    title: "Revoke a team invite",
    description: "Cancels a pending invite (my_team lists them), so its link stops working." + TWO_STEP,
    input: { id: recordId("The invite's id") },
  },
  {
    name: "remove_teammate",
    scope: "self:write",
    title: "Remove a teammate",
    description:
      "Takes someone off your account (my_team lists them). They lose access straight away; what " +
      "they've submitted stays on the account. Only the account owner can do this." + TWO_STEP,
    input: { id: recordId("The person's id") },
  },

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
    name: "pick_meeting_time",
    scope: "self:write",
    title: "Pick a meeting time",
    description:
      "Picks one of the open times your team offered (list_my_booking_requests). They confirm it, " +
      "and you get a calendar invite by email." + TWO_STEP,
    input: {
      id: recordId("The booking request's id - from list_my_booking_requests"),
      date: date("One of the offered days"),
      time: time("One of the offered times on that day"),
      message: z.string().max(1000).optional().describe("Anything they should know first"),
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

  // Marking a chat read moves only your own read marker; they see "Seen".
  if (scopes.includes("messages:write")) {
    server.registerTool(
      "mark_chat_read",
      {
        title: "Messages: mark a chat read",
        description: "Marks a chat on the Messages page read, up to the latest message, and clears its unread count. They see your last message as Seen.",
        inputSchema: { with: recordId("The other admin's id") },
        outputSchema: S.markedConversationRead,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
      },
      async ({ with: other }) =>
        portalCall({
          auth,
          requestId,
          tool: "mark_chat_read",
          path: "messages.php",
          body: { with: other },
          schema: z.object(S.markedConversationRead),
        })
    );
  }

  // Marking a client chat read moves only your own read marker.
  if (scopes.includes("messages:write")) {
    server.registerTool(
      "mark_client_chat_read",
      {
        title: "Messages: mark a client chat read",
        description:
          "Marks a client's chat read for you, up to the latest message, and clears its unread count. Not for the " +
          "Director on a chat they only read (access 'observer').",
        inputSchema: { client: recordId("The client's id - from list_client_chats") },
        outputSchema: S.markedConversationRead,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
      },
      async ({ client }) =>
        portalCall({
          auth,
          requestId,
          tool: "mark_client_chat_read",
          path: "client-messages.php",
          body: { client },
          schema: z.object(S.markedConversationRead),
        })
    );
  }

  if (scopes.includes("self:write")) {
    server.registerTool(
      "mark_my_messages_read",
      {
        title: "Mark my messages read",
        description: "Marks your chat with your account manager read for you, up to the latest message. They see your side as Seen.",
        inputSchema: {},
        outputSchema: S.markedConversationRead,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
      },
      async () =>
        portalCall({
          auth,
          requestId,
          tool: "mark_my_messages_read",
          path: "me/messages.php",
          body: { read: true },
          schema: z.object(S.markedConversationRead),
        })
    );
  }

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
