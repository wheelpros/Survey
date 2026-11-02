/*
|------------------------------------------------------------------------------
| The admin sidebar links, drawn from one list
|------------------------------------------------------------------------------
|
| The links used to be copied into every admin page, so a link added to one
| page went missing from another. Every admin page now has an empty nav with
| this script straight after it, and the list below is the only copy:
|
|   <nav class="side-nav"></nav>
|   <script src="assets/admin-nav.js?v=1"></script>
|
| It runs as the page parses, before anything is painted, so the sidebar
| never flashes empty. Needs assets/permissions.js in <head> for adminCan().
|
| A link with a perm is left out for an admin without that permission
| (api/permissions.php - the Director holds them all). Presentation only:
| every page and endpoint checks the permission itself.
|
| The highlighted link is the page's own, or for a page under it (a phase
| form, an inquiry) the section it belongs to - see PARENT.
|
*/

(function () {

  var ICON_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';

  var LINKS = [
    {
      href: "admin-dashboard.html",
      label: "Dashboard",
      icon: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>'
    },
    {
      href: "admin.html",
      label: "Forms",
      icon: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'
    },
    {
      href: "admin-content.html",
      label: "Content",
      icon: '<rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M3 9h18"/><path d="M9 21V9"/>'
    },
    {
      href: "project-management.html",
      label: "Project Management",
      icon: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'
    },
    {
      // The Director, and whoever was given access.
      href: "inquiries.html",
      label: "Inquiries",
      perm: "inquiries.read",
      icon: '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'
    },
    {
      href: "clients-management.html",
      label: "Clients Management",
      perm: "clients.details",
      icon: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
    },
    {
      href: "sources-files.html",
      label: "Files",
      icon: '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
    },
    {
      // Client meetings.
      href: "admin-calendar.html",
      label: "Calendar",
      perm: "calendar.use",
      icon: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
    },
    {
      // assets/messages-badge.js fills the count.
      href: "admin-messages.html",
      label: "Messages",
      badge: "msgBadge",
      icon: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/>'
    },
    {
      // assets/notifications.js fills the count.
      href: "admin-notifications.html",
      label: "Notifications",
      badge: "notifBadge",
      icon: '<path d="M18 8a6 6 0 1 0-12 0c0 6-3 7-3 7h18s-3-1-3-7"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'
    },
    {
      // What every admin changed (api/admin-events.php).
      href: "event-log.html",
      label: "Tracking",
      perm: "events.view",
      icon: '<path d="M12 8v4l3 2"/><path d="M3.05 11a9 9 0 1 1 .5 4"/><path d="M3 4v5h5"/>'
    },
    {
      href: "settings.html",
      label: "Settings",
      icon: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'
    }
  ];

  // Pages without a link of their own, and the section they sit under.
  var PARENT = {
    "admin-form-builder.html": "admin.html",
    "admin-form-duplicate.html": "admin.html",
    "responses.html": "admin.html",
    "response-details.html": "admin.html",
    "admin-content-form.html": "admin-content.html",
    "project-details.html": "project-management.html",
    "project-form.html": "project-management.html",
    "project-task-details.html": "project-management.html",
    "project-task-form.html": "project-management.html",
    "inquiry-details.html": "inquiries.html",
    "inquiry-form.html": "inquiries.html",
    "inquiry-response.html": "inquiries.html",
    "user-details.html": "clients-management.html",
    "admin-announcement-form.html": "admin-notifications.html"
  };

  function currentRole() {
    try {
      return (JSON.parse(localStorage.getItem("admin_user") || "{}") || {}).role || "";
    } catch (error) {
      // A corrupt admin_user should not take the whole sidebar down with it.
      return "";
    }
  }

  function allowed(link, role) {
    if (!link.perm) return true;

    // Not signed in as an admin: the page's own auth check is already
    // redirecting, so draw everything rather than an empty sidebar.
    if (!role) return true;

    return window.adminCan ? window.adminCan(link.perm) : role === "owner";
  }

  var nav = document.currentScript && document.currentScript.previousElementSibling;
  if (!nav || !nav.classList.contains("side-nav")) return;

  var page = location.pathname.split("/").pop() || "admin-dashboard.html";
  var active = PARENT[page] || page;
  var role = currentRole();

  nav.innerHTML = LINKS.filter(function (link) {
    return allowed(link, role);
  }).map(function (link) {
    return '<a href="' + link.href + '" title="' + link.label + '"'
      + (link.href === active ? ' class="active" aria-current="page"' : "") + '>'
      + ICON_OPEN + link.icon + '</svg>'
      + '<span>' + link.label + '</span>'
      + (link.badge ? '<em class="nav-badge" id="' + link.badge + '" hidden></em>' : "")
      + '</a>';
  }).join("");

})();
