/*
 * The client's own team, as a section of profile.html (#team).
 *
 * Needs on the page: #teamStatus, #teamSeats, #inviteBtn, #people,
 * #invitesCard, #invites, and the #inviteModal dialog with #inviteForm,
 * #inviteEmail, #inviteStatus, #inviteSend. Uses askConfirm() from
 * assets/confirm.js. Talks to api/my-team.php, which decides who may manage.
 */
(function () {
  const token = localStorage.getItem("portal_token");
  if (!token || !document.getElementById("team")) return;

  const statusEl = document.getElementById("teamStatus");
  const modal = document.getElementById("inviteModal");
  const inviteStatus = document.getElementById("inviteStatus");

  function esc(value) {
    const el = document.createElement("div");
    el.textContent = value == null ? "" : String(value);
    return el.innerHTML.replace(/"/g, "&quot;");
  }

  function setStatus(el, message, kind) {
    el.innerText = message;
    el.className = "status" + (kind ? " " + kind : "");
  }

  function formatDate(value) {
    const date = new Date(String(value).replace(" ", "T"));
    return isNaN(date) ? "" : date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  }

  function paint(team) {
    const teammates = team.people.filter(p => !p.is_owner).length;
    const held = teammates + team.invites.filter(i => !i.expired).length;

    document.getElementById("teamSeats").innerText = team.can_manage
      ? `${held} of ${team.max_teammates} teammate places used`
      : "The account owner manages the team.";

    const inviteBtn = document.getElementById("inviteBtn");
    inviteBtn.hidden = !team.can_manage;
    inviteBtn.disabled = held >= team.max_teammates;
    inviteBtn.title = inviteBtn.disabled ? "Remove someone or revoke an invite to add another." : "";

    document.getElementById("people").innerHTML = `
      <ul class="team-list">${team.people.map(p => `
        <li class="team-row">
          <div class="team-initial" aria-hidden="true">${esc(String(p.name).charAt(0).toUpperCase())}</div>
          <div class="team-who">
            <strong dir="auto">${esc(p.name)}</strong>
            <span>${esc(p.email)}</span>
          </div>
          <div class="team-meta"><b>${p.is_owner ? "Owner" : "Teammate"}</b>Joined ${esc(formatDate(p.joined_at))}</div>
          ${team.can_manage ? `<div class="team-actions">${p.is_owner ? "" : `<button class="ghost-btn" type="button" data-remove="${Number(p.id)}" data-name="${esc(p.name)}" aria-label="Remove ${esc(p.name)}">Remove</button>`}</div>` : ""}
        </li>`).join("")}
      </ul>`;

    document.getElementById("invitesCard").hidden = !team.invites.length;
    document.getElementById("invites").innerHTML = !team.invites.length ? "" : `
      <ul class="team-list">${team.invites.map(i => `
        <li class="team-row">
          <div class="team-initial" aria-hidden="true">${esc(String(i.email).charAt(0).toUpperCase())}</div>
          <div class="team-who"><strong>${esc(i.email)}</strong></div>
          <div class="team-meta"><b>${i.expired ? "Expired" : "Pending"}</b>${i.expired ? "" : "Until " + esc(formatDate(i.expires_at))}</div>
          <div class="team-actions">
            <button class="ghost-btn" type="button" data-resend="${Number(i.id)}" aria-label="Resend the invite to ${esc(i.email)}">Resend</button>
            <button class="ghost-btn" type="button" data-revoke="${Number(i.id)}" data-email="${esc(i.email)}" aria-label="Revoke the invite to ${esc(i.email)}">Revoke</button>
          </div>
        </li>`).join("")}
      </ul>`;
  }

  async function api(body) {
    const res = await fetch("api/my-team.php", body ? {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: "Bearer " + token },
      body: JSON.stringify(body)
    } : { headers: { Authorization: "Bearer " + token } });

    if (res.status === 401) {
      localStorage.clear();
      window.location.href = "login.html";
    }

    return res.json();
  }

  async function load() {
    try {
      const data = await api();
      if (data.success) {
        paint(data.team);
      } else {
        setStatus(statusEl, data.message || "Could not load your team.", "err");
      }
    } catch (error) {
      setStatus(statusEl, "Could not load your team. Check your connection and try again.", "err");
    }
  }

  async function act(body) {
    setStatus(statusEl, "");
    try {
      const data = await api(body);
      setStatus(statusEl, data.message || "", data.success && data.emailed !== false ? "ok" : "err");
      if (data.team) paint(data.team);
    } catch (error) {
      setStatus(statusEl, "That didn't go through. Check your connection and try again.", "err");
    }
  }

  document.getElementById("team").addEventListener("click", async function (event) {
    const button = event.target.closest("button");
    if (!button) return;

    if (button.id === "inviteBtn") {
      openInvite();
      return;
    }

    if (button.dataset.remove) {
      const ok = await askConfirm({
        title: "Remove teammate",
        text: button.dataset.name + " will lose access straight away. Anything they've already submitted stays on the account.",
        action: "Remove"
      });
      if (ok) act({ action: "remove", id: Number(button.dataset.remove) });
    }

    if (button.dataset.revoke) {
      const ok = await askConfirm({
        title: "Revoke invite",
        text: "The link sent to " + button.dataset.email + " will stop working.",
        action: "Revoke"
      });
      if (ok) act({ action: "revoke", id: Number(button.dataset.revoke) });
    }

    if (button.dataset.resend) {
      act({ action: "resend", id: Number(button.dataset.resend) });
    }
  });

  function openInvite() {
    setStatus(inviteStatus, "");
    document.getElementById("inviteForm").reset();
    modal.classList.add("show");
    document.getElementById("inviteEmail").focus();
  }

  function closeInvite() {
    modal.classList.remove("show");
    document.getElementById("inviteBtn").focus();
  }

  document.getElementById("inviteClose").addEventListener("click", closeInvite);

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && modal.classList.contains("show")) closeInvite();
  });

  document.getElementById("inviteForm").addEventListener("submit", async function (event) {
    event.preventDefault();

    const send = document.getElementById("inviteSend");
    send.disabled = true;
    setStatus(inviteStatus, "");

    try {
      const data = await api({ action: "invite", email: document.getElementById("inviteEmail").value.trim() });

      if (data.success) {
        paint(data.team);
        setStatus(statusEl, data.message, data.emailed ? "ok" : "err");
        closeInvite();
      } else {
        setStatus(inviteStatus, data.message || "Could not send the invite.", "err");
      }
    } catch (error) {
      setStatus(inviteStatus, "That didn't go through. Check your connection and try again.", "err");
    } finally {
      send.disabled = false;
    }
  });

  // Arriving from a notification or an old team.html link lands on the section.
  if (location.hash === "#team") {
    document.getElementById("team").scrollIntoView();
  }

  load();
})();
