// The staff read tools - one per api/v1 read, each behind the scope that
// area needs. A tool whose scope this connection lacks is never registered,
// so the model only ever chooses between tools that can work.
//
// Each entry is data: what the model sees (title, description, input,
// output) and how it maps onto api/v1 (path, query). registerReadTools()
// turns them into MCP tools. PHP decides which records come back; nothing
// here filters.

import { z } from "zod";

import { portalCall } from "./call.js";
import * as S from "./schemas.js";

export const READ_ONLY = {
  readOnlyHint: true,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: false,
};

// ── Inputs ──────────────────────────────────────────────────────────────

const recordId = (what) => z.number().int().positive().describe(what);
const clientId = recordId("The client's id - from search_clients");
const date = (what) =>
  z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, "must be a date like 2026-10-05")
    .describe(what);
const paging = {
  cursor: z.string().max(500).optional().describe("next_cursor from the previous page"),
  limit: z.number().int().min(1).max(100).optional().describe("Items per page (default 25)"),
};

const ORDER = "Newest first.";

// The nine content types admin-content-form.html offers (CONTENT_TYPE_TITLES
// in api/v1/_helpers.php), as list_content_types returns their ids.
export const CONTENT_TYPE_IDS = [
  "articles", "campaign", "design", "events", "photos",
  "reports", "social_media", "training", "videos",
];

export const READ_TOOLS = [
  // ── Clients ───────────────────────────────────────────────────────────
  {
    name: "search_clients",
    scope: "clients:read",
    title: "Find clients",
    description:
      "Lists the clients you can see - every client for the owner, otherwise the ones " +
      "assigned to you - optionally matching a name, email or company. " + ORDER +
      " Use it to find a client's id for the other tools.",
    input: { query: z.string().max(100).optional().describe("Part of a name, email or company"), ...paging },
    output: S.page(S.clientListItem),
    path: "clients.php",
  },
  {
    name: "get_client_overview",
    scope: "clients:read",
    title: "Client overview",
    description:
      "Everything about one client in a single call: profile, team, open forms, recent " +
      "responses, upcoming and recent content, upcoming meetings and active projects. " +
      "Start here for 'tell me about client X'. Sections your connection can't see are " +
      "listed in not_included.",
    input: { client: clientId },
    output: S.clientOverview,
    path: "clients.php",
    query: ({ client }) => ({ id: client }),
  },
  {
    name: "get_client_team",
    scope: "clients:read",
    title: "Client's team",
    description: "The staff assigned to a client - account managers, super admins and SEO admins.",
    input: { client: clientId },
    output: S.clientTeam,
    path: "clients.php",
    query: ({ client }) => ({ id: client, part: "team" }),
  },
  {
    name: "get_my_client_note",
    scope: "clients:read",
    title: "My note about a client",
    description:
      "Your own private note about a client. Notes belong to whoever wrote them, so " +
      "nobody else's is ever shown. Only the owner and account managers keep notes.",
    input: { client: clientId },
    output: S.clientNote,
    path: "clients.php",
    query: ({ client }) => ({ id: client, part: "note" }),
  },

  // ── Forms ─────────────────────────────────────────────────────────────
  {
    name: "list_forms",
    scope: "forms:read",
    title: "List forms",
    description:
      "Forms built for clients. The owner and account managers see every form; " +
      "everyone else the forms they wrote. " + ORDER,
    input: {
      client: clientId.optional(),
      status: z.enum(["pending_review", "pending", "rejected", "completed"]).optional(),
      ...paging,
    },
    output: S.page(S.formItem),
    path: "forms.php",
  },
  {
    name: "get_form",
    scope: "forms:read",
    title: "Get a form",
    description: "One form with its questions, review state and how many responses it has.",
    input: { id: recordId("The form's id") },
    output: S.formDetail,
    path: "forms.php",
  },
  {
    name: "list_forms_awaiting_review",
    scope: "forms:read",
    title: "Forms awaiting review",
    description:
      "The review queue: forms waiting for approval before their client can see them. " +
      "Reviewers (the owner, account managers) see the whole queue; anyone else their " +
      "own forms still waiting. Approving and returning happen in the portal.",
    input: { ...paging },
    output: S.reviewQueue,
    path: "forms.php",
    query: (args) => ({ ...args, queue: 1 }),
  },
  {
    name: "list_form_responses",
    scope: "forms:read",
    title: "List form responses",
    description:
      "Submitted answers to forms, from the clients you can see. Filter by form or client. " +
      ORDER + " Open one with get_form_response.",
    input: { form: recordId("A form's id").optional(), client: clientId.optional(), ...paging },
    output: S.page(S.responseItem),
    path: "responses.php",
  },
  {
    name: "get_form_response",
    scope: "forms:read",
    title: "Get a form response",
    description:
      "One client's answers, question by question. Uploaded files are named but not " +
      "included - they stay in the portal.",
    input: { id: recordId("The response's id") },
    output: S.responseDetail,
    path: "responses.php",
  },

  // ── Inquiries ─────────────────────────────────────────────────────────
  {
    name: "list_inquiries",
    scope: "inquiries:read",
    title: "List consultation inquiries",
    description: "The public consultation forms, with how many leads each has had. " + ORDER,
    input: { status: z.enum(["active", "inactive"]).optional(), ...paging },
    output: S.page(S.inquiryItem),
    path: "inquiries.php",
  },
  {
    name: "get_inquiry_form",
    scope: "inquiries:read",
    title: "Get a consultation inquiry",
    description: "One consultation inquiry with its intro text and questions.",
    input: { id: recordId("The inquiry's id") },
    output: S.inquiryDetail,
    path: "inquiries.php",
  },
  {
    name: "list_inquiry_leads",
    scope: "inquiries:read",
    title: "List leads",
    description:
      "People who answered a consultation inquiry, with their answers. Without `inquiry`, " +
      "leads from every inquiry. " + ORDER,
    input: {
      inquiry: recordId("An inquiry's id").optional(),
      since: date("Only leads submitted on or after this day").optional(),
      cursor: paging.cursor,
      limit: z.number().int().min(1).max(50).optional().describe("Items per page (default 20)"),
    },
    output: S.page(S.lead),
    path: "leads.php",
  },
  {
    name: "get_inquiry_lead",
    scope: "inquiries:read",
    title: "Get a lead",
    description: "One lead's answers, labelled with the inquiry's questions.",
    input: { id: recordId("The lead's id") },
    output: S.lead.shape,
    path: "leads.php",
  },

  // ── Content ───────────────────────────────────────────────────────────
  {
    name: "list_content",
    scope: "content:read",
    title: "List content",
    description:
      "Posts written for clients. The owner and account managers see them all; everyone " +
      "else their own. Newest first - or, with from/to, forward in time like a calendar. " +
      "A scheduled post goes live on its own when its time comes.",
    input: {
      client: clientId.optional(),
      from: date("Going live on or after this day").optional(),
      to: date("Going live on or before this day").optional(),
      status: z.enum(["draft", "scheduled", "published"]).optional(),
      ...paging,
    },
    output: S.page(S.contentItem),
    path: "content.php",
  },
  {
    name: "get_content",
    scope: "content:read",
    title: "Get a post",
    description: "One post with its full caption, its priority, the team on it and their notes.",
    input: { id: recordId("The post's id") },
    output: S.contentDetail,
    path: "content.php",
  },
  {
    name: "list_content_types",
    scope: "content:read",
    title: "Content types",
    description: "The kinds of post the portal offers (Articles, Videos, Social Media...).",
    input: {},
    output: S.contentTypes,
    path: "content.php",
    query: () => ({ types: 1 }),
  },
  {
    name: "list_content_teammates",
    scope: "content:write",
    title: "Who can work on a post",
    description:
      "The people who can be put on a post: its client's team (account managers, Super Admins and " +
      "Admins assigned to the client). Give the post's id, or a client for a new post. Use their " +
      "ids for team in create_content_draft or update_content_draft.",
    input: {
      id: recordId("A post's id - from list_content").optional(),
      client: clientId.optional(),
    },
    output: S.contentTeammates,
    path: "content.php",
    query: (args) => ({ ...args, teammates: 1 }),
  },

  // ── Calendar ──────────────────────────────────────────────────────────
  {
    name: "get_calendar",
    scope: "calendar:read",
    title: "Calendar",
    description:
      "Meetings with your clients between two days, plus the content going live in the " +
      "same days. Defaults to the next 30 days; at most 366 at a time.",
    input: {
      from: date("First day (default today)").optional(),
      to: date("Last day (default 30 days after from)").optional(),
      client: clientId.optional(),
    },
    output: S.calendar,
    path: "calendar.php",
  },
  {
    name: "list_pending_approvals",
    scope: "calendar:read",
    title: "Meetings waiting for an answer",
    description:
      "Meeting requests still open: those clients sent that staff need to answer, and " +
      "those staff sent that clients haven't answered yet.",
    input: {},
    output: S.pendingApprovals,
    path: "calendar.php",
    query: () => ({ pending: 1 }),
  },
  {
    name: "get_meeting",
    scope: "calendar:read",
    title: "Get a meeting",
    description: "One meeting or meeting request, with its client, topic and notes.",
    input: { id: recordId("The meeting's id") },
    output: S.meeting.shape,
    path: "calendar.php",
  },

  // ── Projects ──────────────────────────────────────────────────────────
  {
    name: "list_projects",
    scope: "projects:read",
    title: "List projects",
    description:
      "Client projects you can see: your clients', the ones you manage and the ones " +
      "you're a member of. " + ORDER,
    input: {
      client: clientId.optional(),
      status: z.enum(["planning", "active", "review", "completed"]).optional(),
      ...paging,
    },
    output: S.page(S.projectItem),
    path: "projects.php",
  },
  {
    name: "get_project",
    scope: "projects:read",
    title: "Get a project",
    description:
      "One project with its members and its phases (the task updates). is_live says whether the " +
      "client can see a phase yet. Each phase carries its stage, role, priority, the team's notes, " +
      "its teammates and its Assigned table (which teammates work on it, what they do, todo / " +
      "in_progress / done - each person sets their own). is_complete is worked out: everyone assigned " +
      "is done. It also lists the gallery, attached files (for the team only) and can_delete.",
    input: { id: recordId("The project's id") },
    output: S.projectDetail,
    path: "projects.php",
  },
  {
    name: "list_phase_teammates",
    scope: "projects:write",
    title: "Who can work on a phase",
    description:
      "The people who can be put on a project's phases: its client's team. Use their ids for " +
      "team and assignments in post_project_update or update_task.",
    input: { id: recordId("The project's id - from list_projects") },
    output: S.phaseTeammates,
    path: "projects.php",
    query: (args) => ({ ...args, teammates: 1 }),
  },
  {
    name: "list_my_phases",
    scope: "projects:read",
    title: "My phases",
    description:
      "Only the phases you are assigned to, with your status on each (todo, in_progress, done) and " +
      "what you noted. For everything on the admin dashboard - teammate phases and content posts " +
      "too - use list_my_tasks. Change your own status with set_my_phase_status.",
    input: {},
    output: S.myPhases,
    path: "projects.php",
    query: () => ({ mine: 1 }),
  },
  {
    name: "list_my_tasks",
    scope: "projects:read",
    title: "My tasks",
    description:
      "Everything on your admin dashboard's My tasks: phases you are assigned to, phases you are a " +
      "teammate on, and content posts you are on (when this connection can read content), each " +
      "with your own status and due date, soonest first, plus the counts. Change a phase with " +
      "set_my_phase_status and a post with set_my_post_status. For the Director it " +
      "also returns team: the dashboard's Team overview - everyone's tasks, each with its " +
      "person, status and whether it is overdue, the counts, and what needs attention (phases nobody is " +
      "assigned to, forms to review, meeting requests waiting). Use it for \"what's late across the team?\". " +
      "Other people's statuses are read-only: each person sets their own.",
    input: {},
    output: S.myTasks,
    path: "projects.php",
    query: () => ({ tasks: 1 }),
  },

  // ── Notifications ─────────────────────────────────────────────────────
  {
    name: "list_my_notifications",
    scope: "notifications:read",
    title: "My notifications",
    description: "Your own portal notifications. " + ORDER,
    input: { unread: z.boolean().optional().describe("Only unread ones"), ...paging },
    output: S.notifications,
    path: "notifications.php",
    query: ({ unread, ...rest }) => ({ ...rest, unread: unread ? 1 : undefined }),
  },

  // ── Event log (the owner's) ───────────────────────────────────────────
  {
    name: "list_admin_events",
    scope: "events:read",
    title: "Event log",
    description:
      "What every admin changed in the portal - in the browser or through an AI assistant - with who, " +
      "when and a one-line summary: projects, phases, content, forms, inquiries, meetings, clients, the " +
      "team and settings. " + ORDER + " Kept for 12 months. Filter by admin (an id from get_settings), " +
      "area, client (from search_clients), dates or text. Use it for \"what did Sara do this week?\" or " +
      "\"who deleted that project?\".",
    input: {
      actor_id: recordId("Only this admin's changes - an id from get_settings").optional(),
      area: z
        .enum(["client", "project", "phase", "content", "form", "inquiry", "meeting", "source", "announcement", "admin", "settings"])
        .optional()
        .describe("Only one area; admin means changes to the team"),
      client_id: recordId("Only changes about this client - from search_clients").optional(),
      from: date("On or after this day").optional(),
      to: date("On or before this day").optional(),
      q: z.string().max(100).optional().describe("Text in the summary, the item's name or the admin's name"),
      ...paging,
    },
    output: S.adminEvents,
    path: "events.php",
  },

  // ── Settings (the owner's) ────────────────────────────────────────────
  {
    name: "get_settings",
    scope: "settings:read",
    title: "Portal settings",
    description:
      "Everything on the Settings page: the website address, logo, login page slides " +
      "(with their ids, in display order) and the admin team - each admin's id, role, " +
      "whether they can sign in, and who manages them. Start here before changing a setting " +
      "or an admin; the ids the change tools take come from this.",
    input: {},
    output: S.settings,
    path: "settings.php",
  },

  // ── A client's own account (self:read) ────────────────────────────────
  // Only ever the caller's own records - api/v1/me takes the client from
  // the token, never from an argument.
  {
    name: "my_overview",
    scope: "self:read",
    title: "My overview",
    description:
      "What's waiting on you and what's coming up: meeting requests to answer, upcoming " +
      "meetings, forms to fill in, recent posts, your projects and unread notifications. " +
      "Start here.",
    input: {},
    output: S.myOverview,
    path: "me/overview.php",
  },
  {
    name: "my_calendar",
    scope: "self:read",
    title: "My calendar",
    description: "Your meetings between two days, and the posts that went live in them. Defaults to the next 30 days.",
    input: {
      from: date("First day (default today)").optional(),
      to: date("Last day (default 30 days after from)").optional(),
    },
    output: S.myCalendar,
    path: "me/calendar.php",
  },
  {
    name: "get_my_meeting",
    scope: "self:read",
    title: "One of my meetings",
    description: "One meeting or meeting request, with its topic and notes.",
    input: { id: recordId("The meeting's id") },
    output: S.myMeeting.shape,
    path: "me/calendar.php",
  },
  {
    name: "my_forms",
    scope: "self:read",
    title: "My forms",
    description: "Forms your team has sent you: to fill in (pending) or sent (completed). " + ORDER,
    input: { status: z.enum(["pending", "completed"]).optional(), ...paging },
    output: S.page(S.myFormItem),
    path: "me/forms.php",
  },
  {
    name: "get_my_form",
    scope: "self:read",
    title: "One of my forms",
    description: "A form's questions - and your answers, if you've sent it.",
    input: { id: recordId("The form's id") },
    output: S.myForm,
    path: "me/forms.php",
  },
  {
    name: "my_content",
    scope: "self:read",
    title: "My content",
    description: "Posts your team has published for you. Newest first - or, with from/to, forward in time.",
    input: {
      from: date("Live on or after this day").optional(),
      to: date("Live on or before this day").optional(),
      ...paging,
    },
    output: S.myPosts,
    path: "me/content.php",
  },
  {
    name: "my_projects",
    scope: "self:read",
    title: "My projects",
    description: "Your projects and how far along each is.",
    input: {},
    output: S.myProjects,
    path: "me/projects.php",
  },
  {
    name: "get_my_project",
    scope: "self:read",
    title: "One of my projects",
    description: "A project with the updates your team has published on it.",
    input: { id: recordId("The project's id") },
    output: S.myProject,
    path: "me/projects.php",
  },
  {
    name: "my_notifications",
    scope: "self:read",
    title: "My notifications",
    description: "Your portal notifications. " + ORDER,
    input: { unread: z.boolean().optional().describe("Only unread ones"), ...paging },
    output: S.notifications,
    path: "me/notifications.php",
    query: ({ unread, ...rest }) => ({ ...rest, unread: unread ? 1 : undefined }),
  },
];

/** Registers every read tool `scopes` allows. Returns their names. */
export function registerReadTools(server, { auth, requestId, scopes }) {
  const registered = [];
  for (const tool of READ_TOOLS) {
    if (!scopes.includes(tool.scope)) continue;
    server.registerTool(
      tool.name,
      {
        title: tool.title,
        description: tool.description,
        inputSchema: tool.input,
        outputSchema: tool.output,
        annotations: READ_ONLY,
      },
      async (args) =>
        portalCall({
          auth,
          requestId,
          tool: tool.name,
          path: tool.path,
          query: tool.query ? tool.query(args) : args,
          schema: z.object(tool.output),
        })
    );
    registered.push(tool.name);
  }
  return registered;
}
