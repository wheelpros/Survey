/*
| XSS canary (audit T-06, P0-0). Seeds a _test database with marker payloads
| (seed.php), serves the real pages with `php -S`, opens each page that shows
| those fields as the person who would see them, and fails if any payload
| ran or any hostile link survived as a clickable javascript:/data: href.
|
|   DB_HOST=127.0.0.1 DB_NAME=..._test DB_USER=... DB_PASS=... node tests/e2e/xss-canary.mjs
|
| Playwright comes from tests/e2e/node_modules (CI installs it there), or
| from the folder named by PLAYWRIGHT_FROM. PLAYWRIGHT_CHROMIUM may point at
| a browser binary. It drops every table in DB_NAME, so DB_NAME must end in _test.
*/

import { execFileSync, spawn } from "node:child_process";
import { createRequire } from "node:module";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import net from "node:net";

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, "..", "..");

if (!/_test$/.test(process.env.DB_NAME || "")) {
    console.error("Refusing to run: DB_NAME must end in _test (this drops every table in it).");
    process.exit(2);
}

async function loadPlaywright() {
    if (process.env.PLAYWRIGHT_FROM) {
        return createRequire(join(process.env.PLAYWRIGHT_FROM, "package.json"))("playwright");
    }
    return import("playwright");
}

/*
| The pages, each opened by someone who would see the field. `pending` marks
| a page whose fix belongs to a later step of the audit plan: it is reported
| but does not fail the run until that step removes the mark.
*/
const PAGES = [
    // Retired (U-01): it forwards to Forms and shows nothing itself.
    { label: "responses.html, retired (U-01)", as: "tok-owner", url: "responses.html?survey_id=102", shows: "Acme onboarding", lands: "/admin.html" },
    { label: "admin.html, the responses list", as: "tok-owner", url: "admin.html", shows: "Acme onboarding" },
    { label: "response-details.html", as: "tok-owner", url: "response-details.html?id=200", shows: "Your goals" },
    { label: "admin-content.html (U-02)", as: "tok-owner", url: "admin-content.html?id=300", shows: "Caption" },
    { label: "admin-content-form.html (U-02)", as: "tok-owner", url: "admin-content-form.html?id=300", shows: "Caption" },
    { label: "content.html, the client's feed (U-05)", as: "tok-client", url: "content.html?id=303", shows: "Caption" },
    { label: "project-task-form.html (U-03)", as: "tok-owner", url: "project-task-form.html?id=601&project=501", shows: "Phase" },
    { label: "project-task-details.html (U-05)", as: "tok-owner", url: "project-task-details.html?id=601&project=501", shows: "Phase" },
    { label: "user-project-details.html, the client's project (U-05)", as: "tok-bob", url: "user-project-details.html?id=501", shows: "Phase",
      open: (tab) => tab.getByRole("button", { name: "View" }).first().click() },
    { label: "survey.html (U-04)", as: "tok-client", url: "survey.html?id=102", shows: "logoZ" },
    { label: "messages.html, the client's chat", as: "tok-client", url: "messages.html", shows: "Hi Amy" },
    { label: "admin-messages.html, an account manager's client chat", as: "tok-am", url: "admin-messages.html?client=10", shows: "Hi Amy" },
];

/*
| The shared sanitizer, as a unit table. Each input must come out with no
| script and no href outside http(s)/mailto; the last must keep its markup.
*/
const SANITIZER_CASES = [
    { input: `<img src=x onerror="(window.__xss=window.__xss||[]).push('unit')">`, keeps: [] },
    { input: `<a href="javascript:alert(1)">a</a>`, keeps: [] },
    { input: `<a href="java&#9;script:alert(1)">b</a>`, keeps: [] },
    { input: `<a href="java&#10;script:alert(1)">c</a>`, keeps: [] },
    { input: `<a href="&#1;javascript:alert(1)">d</a>`, keeps: [] },
    { input: `<a href=" data:text/html,x">e</a>`, keeps: [] },
    { input: `<svg><script>(window.__xss=window.__xss||[]).push('svg')</script></svg>`, keeps: [] },
    { input: `<p style="color:red" onclick="x()"><b>Bold</b> <a href="https://example.test/a">site</a> <a href="mailto:a@example.test">mail</a></p>`,
      keeps: ["https://example.test/a", "mailto:a@example.test"], text: "Bold site mail" },
    // Alignment is the one style the editor's toolbar sets; it survives.
    { input: `<p style="text-align: center; color: red">Centred</p>`, keeps: [], text: "Centred", centred: true },
];

let failures = 0;
let checks = 0;
function check(label, ok, detail = "") {
    checks++;
    if (!ok) {
        failures++;
        console.log(`FAIL  ${label}${detail ? `\n      ${detail}` : ""}`);
    }
}

function freePort() {
    return new Promise((resolve) => {
        const s = net.createServer();
        s.listen(0, "127.0.0.1", () => {
            const { port } = s.address();
            s.close(() => resolve(port));
        });
    });
}

async function waitForPort(port) {
    for (let i = 0; i < 100; i++) {
        const up = await new Promise((resolve) => {
            const c = net.connect(port, "127.0.0.1", () => { c.end(); resolve(true); });
            c.on("error", () => resolve(false));
        });
        if (up) return;
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error("php -S did not start");
}

const php = process.env.PHP_BINARY || "php";
execFileSync(php, [join(here, "seed.php")], { stdio: ["ignore", "ignore", "inherit"], env: process.env });

const port = await freePort();
const base = `http://127.0.0.1:${port}/`;
// SMTP at a closed local port: nothing a page triggers can send mail.
const server = spawn(php, ["-S", `127.0.0.1:${port}`, "-t", root], {
    cwd: root, stdio: "ignore",
    env: { ...process.env, APP_URL: "http://portal.test", SMTP_HOST: "127.0.0.1", SMTP_PORT: "9" },
});
process.on("exit", () => server.kill());

let browser;
try {
    await waitForPort(port);
    const { chromium } = await loadPlaywright();
    browser = await chromium.launch(process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {});

    async function whoIs(token) {
        const staff = await fetch(base + "api/admin-session.php", { headers: { Authorization: "Bearer " + token } });
        const admin = staff.ok ? (await staff.json().catch(() => ({}))).admin : null;
        if (admin) return { kind: "admin", user: admin };
        const client = await fetch(base + "api/check-auth.php", { headers: { Authorization: "Bearer " + token } });
        return { kind: "client", user: (await client.json()).user };
    }

    for (const page of PAGES) {
        const who = await whoIs(page.as);
        const ctx = await browser.newContext();
        await ctx.addInitScript(([kind, token, user]) => {
            const prefix = kind === "admin" ? "admin" : "portal";
            localStorage.setItem(prefix + "_token", token);
            localStorage.setItem(prefix + "_user", JSON.stringify(user || {}));
        }, [who.kind, page.as, who.user]);
        const tab = await ctx.newPage();
        tab.on("dialog", (d) => d.dismiss().catch(() => {}));
        let found;
        try {
            await tab.goto(base + page.url, { waitUntil: "networkidle", timeout: 20000 });
            if (page.open) await page.open(tab);
            // The field must be on screen, or a clean result proves nothing.
            await tab.waitForFunction((t) => document.body.innerText.includes(t), page.shows, { timeout: 10000 });
            await tab.waitForTimeout(1000);
            found = await tab.evaluate(() => ({
                ran: window.__xss || [],
                links: [...document.querySelectorAll("a[href]")]
                    .filter((a) => (a.getAttribute("href") || "").includes("__xss")
                        && !["http:", "https:", "mailto:"].includes(a.protocol))
                    .map((a) => a.getAttribute("href")),
                url: location.pathname,
            }));
        } catch (e) {
            found = {
                error: String(e.message || e).split("\n")[0],
                at: tab.url().replace(base, ""),
                text: (await tab.evaluate(() => document.body.innerText).catch(() => "")).replace(/\s+/g, " ").slice(0, 160),
            };
        }
        await ctx.close();

        const clean = !found.error && found.ran.length === 0 && found.links.length === 0
            && (!page.lands || found.url === page.lands);
        const detail = JSON.stringify(found);
        if (page.pending) {
            console.log(`${clean ? "ok   " : "PEND "} ${page.label} (pending ${page.pending})${clean ? "" : `\n      ${detail}`}`);
            continue;
        }
        check(`${page.label}: no payload runs and no script link survives`, clean, detail);
    }

    // The shared sanitizer, on its own.
    const ctx = await browser.newContext();
    const tab = await ctx.newPage();
    await tab.goto(base + "login.html", { waitUntil: "load" });
    let loaded = true;
    try {
        await tab.addScriptTag({ url: base + "assets/sanitize-html.js" });
        loaded = await tab.evaluate(() => typeof window.sanitizeHtml === "function");
    } catch {
        loaded = false;
    }
    check("assets/sanitize-html.js loads and defines window.sanitizeHtml", loaded);
    if (loaded) {
        for (const c of SANITIZER_CASES) {
            const out = await tab.evaluate(async (input) => {
                window.__xss = [];
                const html = window.sanitizeHtml(input);
                const box = document.createElement("div");
                box.innerHTML = html;
                document.body.appendChild(box);
                await new Promise((r) => setTimeout(r, 200));
                const hrefs = [...box.querySelectorAll("a[href]")].map((a) => ({ href: a.getAttribute("href"), protocol: a.protocol }));
                const attrs = [...box.querySelectorAll("*")].flatMap((el) => [...el.attributes])
                    .filter((a) => a.name.startsWith("on") || (a.name === "style" && !/^text-align: (left|center|right|justify);?$/.test(a.value)))
                    .map((a) => a.name);
                const centred = !!box.querySelector("p[style]") && box.querySelector("p").style.textAlign === "center";
                const res = { html, hrefs, attrs, centred, ran: window.__xss, text: box.textContent.replace(/\s+/g, " ").trim(),
                    scripts: box.querySelectorAll("script, svg, img[onerror], iframe").length };
                box.remove();
                return res;
            }, c.input);
            const badHref = out.hrefs.some((h) => !["http:", "https:", "mailto:"].includes(h.protocol));
            const kept = c.keeps.every((k) => out.hrefs.some((h) => h.href === k));
            check(`sanitizer: ${c.input.slice(0, 48)}`, out.ran.length === 0 && !badHref && out.attrs.length === 0
                && out.scripts === 0 && kept && (c.text === undefined || out.text === c.text)
                && (!c.centred || out.centred), JSON.stringify(out));
        }
    }
    await ctx.close();
} finally {
    if (browser) await browser.close();
    server.kill();
}

console.log(`${failures === 0 ? "ok" : "FAILED"} - ${checks} checks, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
