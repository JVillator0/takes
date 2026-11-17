// Takes: low-fidelity wireframes for the public app and the admin console.
// Each sheet = one screen at 1440x900 + a notes column explaining the numbered decisions.
// Run: node build.mjs   (from this folder). Output: ../NN-name.png and ../index.html
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, "..");
const PW = process.env.PLAYWRIGHT_CORE ?? "playwright-core"; // path to a playwright-core install
const require = createRequire(import.meta.url);
const { chromium } = require(PW);

const CSS = `
*{box-sizing:border-box}
body{margin:0;background:#e6e6e6;font-family:"Helvetica Neue",Arial,sans-serif;color:#222}
.sheet{padding:36px 40px;width:max-content}
.sheet h1{font-size:34px;margin:0 0 4px}
.sheet .sub{font-size:19px;color:#555;margin:0 0 22px;max-width:1900px}
.layout{display:flex;gap:28px;align-items:flex-start}
.notes{width:500px;font-size:17px;line-height:1.4}
.notes h3{margin:0 0 10px;font-size:19px}
.notes ol{margin:0;padding-left:0;list-style:none}
.notes li{display:flex;gap:10px;margin:0 0 12px}
.n{display:inline-flex;min-width:26px;height:26px;border-radius:13px;background:#d9480f;color:#fff;font-style:normal;font-weight:700;font-size:15px;align-items:center;justify-content:center;padding:0 7px;vertical-align:middle;flex:none}
.screen{width:1440px;height:900px;border:3px solid #555;background:#f7f7f7;position:relative;overflow:hidden;font-size:15px}
.screen.m{width:390px;height:844px;border-radius:28px}
.row{display:flex;gap:12px;align-items:center}
.col{display:flex;flex-direction:column;gap:10px}
.grow{flex:1}
.top{height:56px;background:#fff;border-bottom:2px solid #bbb;display:flex;align-items:center;padding:0 22px;gap:18px}
.logo{font-weight:800;font-size:20px;letter-spacing:-.5px}
.logo b{display:inline-block;width:22px;height:22px;border:3px solid #222;border-radius:5px;margin-right:6px;vertical-align:-4px}
.crumb{color:#666}
.pill{border:2px solid #999;border-radius:20px;padding:5px 12px;background:#fff;font-size:14px}
.pill.ok{border-color:#2e7d32;color:#2e7d32}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:2px solid #777;background:#fff;border-radius:7px;padding:9px 14px;font-weight:600;font-size:14px;white-space:nowrap}
.btn.p{background:#222;color:#fff;border-color:#222}
.btn.g{background:#2e7d32;border-color:#2e7d32;color:#fff}
.btn.d{opacity:.45}
.btn.s{padding:5px 9px;font-size:13px}
.btn.lg{padding:13px 18px;font-size:16px}
.box{border:2px solid #aaa;background:#fff;border-radius:9px}
.pad{padding:16px}
.lbl{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#666;margin-bottom:5px}
.inp{border:2px solid #aaa;border-radius:7px;background:#fff;padding:9px 11px;color:#444;min-height:40px}
.ta{min-height:120px}
.seg{display:flex;border:2px solid #aaa;border-radius:7px;overflow:hidden;background:#fff}
.seg span{flex:1;text-align:center;padding:8px 4px;border-right:2px solid #ddd;font-size:13px}
.seg span:last-child{border-right:0}
.seg span.on{background:#222;color:#fff;font-weight:700}
.muted{color:#777}
.small{font-size:13px}
.xs{font-size:12px}
.ph{background:#e3e3e3;border:2px solid #bbb;border-radius:7px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#888;font-size:13px}
.ph:before,.ph:after{content:"";position:absolute;inset:0;background:linear-gradient(to top right,transparent calc(50% - 1px),#c8c8c8 50%,transparent calc(50% + 1px))}
.ph:after{background:linear-gradient(to top left,transparent calc(50% - 1px),#c8c8c8 50%,transparent calc(50% + 1px))}
.ph span{position:relative;z-index:1;background:#e3e3e3;padding:2px 6px}
.badge{display:inline-flex;align-items:center;gap:5px;border:2px solid #999;border-radius:12px;padding:2px 9px;font-size:12px;font-weight:700;background:#fff}
.b-q{border-color:#888;color:#555}
.b-p{border-color:#b26a00;color:#b26a00}
.b-s{border-color:#2e7d32;color:#2e7d32}
.b-f{border-color:#c62828;color:#c62828}
.b-w{border-color:#b26a00;color:#b26a00;border-style:dashed}
.card{border:2px solid #aaa;background:#fff;border-radius:9px;overflow:hidden}
.card .meta{padding:9px 10px;display:flex;flex-direction:column;gap:5px}
.grid{display:grid;gap:14px}
.est{border:2px dashed #555;border-radius:9px;padding:12px;background:#fafafa}
.big{font-size:24px;font-weight:800}
.huge{font-size:34px;font-weight:800}
.ok{color:#2e7d32}.bad{color:#c62828}.warn{color:#b26a00}
.tbl{width:100%;border-collapse:collapse;background:#fff;font-size:13px}
.tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#666;border-bottom:2px solid #bbb;padding:8px 10px;background:#f0f0f0}
.tbl td{border-bottom:1px solid #ddd;padding:8px 10px}
.tbl td.r,.tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.tbl tr.tot td{font-weight:800;border-top:2px solid #999;background:#f6f6f6}
.overlay{position:absolute;inset:0;background:rgba(0,0,0,.35)}
.modal{position:absolute;background:#fff;border:3px solid #444;border-radius:12px;box-shadow:0 10px 0 rgba(0,0,0,.08)}
.drawer{position:absolute;top:0;right:0;bottom:0;background:#fff;border-left:3px solid #444}
.toast{position:absolute;border:2px solid #444;background:#fff;border-radius:9px;padding:12px 14px;box-shadow:0 6px 0 rgba(0,0,0,.08)}
.diff-del{background:#fde2e2;text-decoration:line-through}
.diff-add{background:#d9f2dc}
.spin{display:inline-block;width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%}
.bar{height:9px;background:#ddd;border-radius:5px;overflow:hidden}
.bar i{display:block;height:100%;background:#999}
.live{display:inline-flex;gap:6px;align-items:center;font-size:13px;font-weight:700;color:#2e7d32}
.live:before{content:"";width:9px;height:9px;border-radius:50%;background:#2e7d32}
/* admin (Filament-like) */
.adm{display:flex;height:100%}
.adm .env{position:absolute;inset:0;border:5px solid #c62828;pointer-events:none;z-index:5}
.side{width:250px;background:#fff;border-right:2px solid #ccc;padding:14px 12px;display:flex;flex-direction:column;gap:3px;font-size:14px}
.side .it{padding:7px 10px;border-radius:7px;display:flex;gap:9px;align-items:center}
.side .it.on{background:#ececec;font-weight:700}
.side .grp{margin:12px 0 4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#888}
.ico{width:16px;height:16px;border:2px solid #777;border-radius:4px;flex:none}
.amain{flex:1;display:flex;flex-direction:column;min-width:0}
.atop{height:56px;background:#fff;border-bottom:2px solid #ccc;display:flex;align-items:center;gap:14px;padding:0 20px}
.envb{background:#c62828;color:#fff;font-weight:800;font-size:12px;padding:4px 9px;border-radius:5px}
.abody{padding:20px 24px;display:flex;flex-direction:column;gap:16px;overflow:hidden}
.tabs{display:flex;gap:4px;border-bottom:2px solid #ccc}
.tabs span{padding:9px 14px;font-weight:600;color:#666}
.tabs span.on{color:#222;border-bottom:3px solid #222;margin-bottom:-2px}
.stat{border:2px solid #bbb;background:#fff;border-radius:10px;padding:13px 15px;flex:1}
.stat .v{font-size:24px;font-weight:800;margin-top:3px}
.chart{border:2px solid #bbb;background:#fff;border-radius:10px;padding:12px 14px}
.h2{font-size:22px;font-weight:800}
.bc{font-size:13px;color:#777}
`;

const ph = (label, h, extra = "") => `<div class="ph" style="height:${h}px;${extra}"><span>${label}</span></div>`;
const n = (k) => `<i class="n">${k}</i>`;
const badge = (s) => ({
  pending: `<span class="badge b-w"><span class="spin"></span> Submitting</span>`,
  waiting: `<span class="badge b-w">Waiting for capacity</span>`,
  queued: `<span class="badge b-q">Queued</span>`,
  processing: `<span class="badge b-p"><span class="spin"></span> Processing</span>`,
  succeeded: `<span class="badge b-s">Done</span>`,
  failed: `<span class="badge b-f">Failed</span>`,
}[s]);

// ---------- public chrome ----------
const appTop = (crumbs, opts = {}) => `
<div class="top">
  <div class="logo"><b></b>Takes</div>
  <div class="crumb">${crumbs}</div>
  <div class="grow"></div>
  ${opts.live === false ? "" : `<span class="live">Live</span>`}
  <span class="pill">Available <b>${opts.balance ?? "$10.3336"}</b>${opts.hold ? ` · on hold ${opts.hold}` : ""}</span>
  <span class="btn s">Buy credits</span>
  <div class="ph" style="width:34px;height:34px;border-radius:17px"></div>
</div>`;

const takeCard = (t) => `
<div class="card">
  ${t.img ? ph(t.img, 150) : `<div class="ph" style="height:150px;background:#efefef">${t.state === "failed" ? "<span>No image</span>" : `<span>${t.state === "processing" ? "Rendering" : "Waiting"}</span>`}</div>`}
  <div class="meta">
    <div class="row">${badge(t.state)}<span class="grow"></span><span class="xs muted">${t.size}</span></div>
    <div class="small" style="height:34px;overflow:hidden">${t.prompt}</div>
    <div class="row xs">${t.money}</div>
    ${t.extra ? `<div class="xs muted">${t.extra}</div>` : ""}
  </div>
</div>`;

const composer = (o = {}) => `
<div class="col" style="gap:14px">
  <div><div class="lbl">Prompt</div><div class="inp ta">${o.prompt ?? "A lighthouse on a cliff at dusk, warm light, cinematic wide shot"}</div><div class="row xs muted" style="margin-top:4px"><span>Tip: mention light, lens and mood</span><span class="grow"></span><span>${o.chars ?? "64"} / 4000</span></div></div>
  <div><div class="lbl">Size</div><div class="seg"><span class="${o.size === 0 || o.size === undefined ? "on" : ""}">▢ Square<br><span class="xs">1024×1024</span></span><span class="${o.size === 1 ? "on" : ""}">▭ Landscape<br><span class="xs">1536×1024</span></span><span class="${o.size === 2 ? "on" : ""}">▯ Portrait<br><span class="xs">1024×1536</span></span></div></div>
  <div><div class="lbl">Quality</div><div class="seg"><span>Low</span><span class="${o.q === 2 ? "" : "on"}">Medium</span><span class="${o.q === 2 ? "on" : ""}">High</span></div></div>
  <div><div class="lbl">Reference images (optional, up to 4)</div>
    <div class="row">${ph("ref", 46, "width:46px")}<div class="inp grow small">https://images.example.com/lighthouse.jpg</div><span class="btn s">✕</span></div>
    <div class="small muted" style="margin-top:6px">+ Add reference URL</div></div>
  ${o.estimate ?? `
  <div class="est">
    <div class="row"><div class="lbl" style="margin:0">Estimated price</div><span class="grow"></span><span class="xs muted">updates as you type</span></div>
    <div class="big">$0.0677 – $0.0960</div>
    <div class="small">You pay the actual cost + 25% when the take finishes. <b>Failed takes are free.</b></div>
    <div class="xs muted" style="margin-top:6px">We hold up to $0.0960 from your balance while it runs.</div>
  </div>`}
  ${o.cta ?? `<span class="btn p lg">Generate take · up to $0.0960</span>`}
</div>`;

// ---------- sheets ----------
const sheets = [];
const add = (file, title, sub, screen, notes, opts = {}) => sheets.push({ file, title, sub, screen, notes, opts });

// P01 Landing
add("p01-landing", "P01 · Landing (signed out)", "The first screen an evaluator sees. It sells the product idea in one line and makes the money rule explicit before sign-up.", `
<div class="screen">
  <div class="top"><div class="logo"><b></b>Takes</div><span class="crumb">How it works</span><span class="crumb">Pricing</span><div class="grow"></div><span class="btn">Sign in</span><span class="btn p">Start free</span></div>
  <div class="row" style="padding:60px 70px;gap:60px;align-items:flex-start">
    <div class="col" style="width:560px;gap:18px">
      <div class="huge" style="font-size:50px;line-height:1.05">Describe it.<br>Take it.<br>Remix it. ${n(1)}</div>
      <div style="font-size:19px;color:#555">Generate images, iterate on them as versions, and always see the price before you press Generate.</div>
      <div class="row"><span class="btn p lg">Start with $1.00 free ${n(2)}</span><span class="btn lg">Try a demo account</span></div>
      <div class="small muted">No card. Credits are simulated for this demo.</div>
    </div>
    <div class="grow box pad" style="height:380px">
      <div class="row"><b>Lighthouse series</b><span class="grow"></span><span class="xs muted">5 takes</span></div>
      <div class="grid" style="grid-template-columns:repeat(3,1fr);margin-top:12px">${ph("take #1", 140)}${ph("take #2 · warmer", 140)}${ph("take #3 · portrait", 140)}${ph("take #4", 140)}<div class="ph" style="height:140px;background:#efefef"><span>Processing…</span></div></div>
    </div>
  </div>
  <div class="row" style="padding:0 70px;gap:20px">
    <div class="box pad grow"><div class="lbl">1 · Describe</div>Write a prompt, pick size and quality. The price range shows before you start.</div>
    <div class="box pad grow"><div class="lbl">2 · Take</div>Every generation is a take, saved in a project, with its exact cost.</div>
    <div class="box pad grow"><div class="lbl">3 · Remix ${n(3)}</div>Branch any take, or describe a change ("warmer, portrait") and review it before it runs.</div>
  </div>
  <div class="box pad" style="margin:20px 70px 0">
    <div class="row"><div class="col" style="gap:4px"><div class="lbl">Clear pricing ${n(4)}</div><div>Pay per take from prepaid credits. See the price range before you generate, the exact amount after. <b>Failed takes cost nothing.</b></div></div><span class="grow"></span>
    <table class="tbl" style="width:520px"><tr><th>Example take</th><th class="r">Price range</th></tr><tr><td>Square · medium · short prompt</td><td class="r">$0.0581 – $0.0863</td></tr><tr><td>Landscape · high · 2 references</td><td class="r">$0.3577 – $0.4674</td></tr></table></div>
  </div>
</div>`, [
  "Product name <b>Takes</b>: video-editing vocabulary. A generation is a take; iterating is natural.",
  "Welcome credit for new customers so evaluators can generate right away (recorded as a <i>promo grant</i> ledger entry, not a purchase).",
  "The differentiator is promised up front: remix + <i>Describe a change</i>.",
  "Pricing is stated before sign-up as ranges per take, plus \"failed = free\". Provider cost and our margin stay internal (D-023); real numbers from the Pixelforge rates.",
]);

// P02 Sign in
add("p02-sign-in", "P02 · Sign in (customer guard)", "Customers live in their own table and guard. Demo accounts remove friction for evaluators.", `
<div class="screen">
  <div class="top"><div class="logo"><b></b>Takes</div><div class="grow"></div><span class="btn">Create account</span></div>
  <div class="row" style="justify-content:center;gap:40px;padding-top:80px;align-items:flex-start">
    <div class="box pad col" style="width:430px;gap:14px;padding:26px">
      <div class="big">Sign in</div>
      <div><div class="lbl">Email</div><div class="inp">ana@example.com</div></div>
      <div><div class="lbl">Password</div><div class="inp">••••••••</div></div>
      <div class="row small"><span>☐ Remember me</span><span class="grow"></span><span class="muted">Forgot password?</span></div>
      <span class="btn p lg">Sign in ${n(1)}</span>
      <div class="small muted" style="text-align:center">New here? <b>Create an account</b> · you get $1.00 to start</div>
    </div>
    <div class="box pad col" style="width:430px;gap:12px;padding:26px;border-style:dashed">
      <div class="lbl">Evaluating this take-home? ${n(2)}</div>
      <div class="small">One click, no password. Shared accounts: balances change as people use them.</div>
      <span class="btn">Demo customer · $10.00 credit</span>
      <span class="btn">Low balance · $0.01 (see blocking) ${n(3)}</span>
      <span class="btn">New customer · $0.00 (empty states)</span>
      <div class="xs muted">Admin console: /admin (separate login, see the README).</div>
    </div>
  </div>
</div>`, [
  "Starter-kit sign-in page, unchanged, on the <b>customer</b> guard and <code>customers</code> table. <code>users</code> stays Laravel's default and belongs to admins (Filament).",
  "Demo accounts are seeded and only enabled when <code>DEMO_LOGIN_ENABLED=true</code> (production demo only).",
  "Each demo account exists to show one behavior: normal flow, insufficient balance, empty states.",
]);

// P03 Projects index
add("p03-projects", "P03 · Projects (home after sign in)", "Takes are organised in projects (decision: projects + grid).", `
<div class="screen">
  ${appTop("Projects")}
  <div style="padding:26px 34px">
    <div class="row"><div class="h2" style="font-size:26px;font-weight:800">Projects</div><span class="grow"></span><div class="inp" style="width:260px;min-height:36px">Search projects</div><span class="btn p">+ New project ${n(1)}</span></div>
    <div class="grid" style="grid-template-columns:repeat(4,1fr);margin-top:20px">
      ${["Lighthouse series|8 takes · $0.3525|3 in progress", "Untitled project|0 takes · $0.0000|"].map((p) => { const [a, b, c] = p.split("|"); return `<div class="card">${b.startsWith("0") ? `<div class="ph" style="height:170px;background:#efefef"><span>Empty</span></div>` : ph("cover = latest take", 170)}<div class="meta"><b>${a}</b><div class="small muted">${b} spent ${n(2)}</div>${c ? `<div class="row">${badge("processing")}<span class="xs muted">${c}</span></div>` : `<div class="xs muted">updated 2 h ago</div>`}</div></div>`; }).join("")}
    </div>
    <div class="box pad" style="margin-top:22px;border-style:dashed;width:520px"><div class="lbl">Empty state (new customer) ${n(3)}</div><div>"Start your first project". One button. A sample prompt is pre-filled in the composer.</div></div>
  </div>
</div>`, [
  "Creating a project is one field (name). Default project <i>Untitled project</i> is created on first visit so the composer is never blocked.",
  "Spend per project = sum of settled charges of its takes (Pixelforge + AI suggestions). Same numbers as the billing page.",
  "Empty state with a pre-filled example prompt (no blank page).",
]);

// P04 Workspace
add("p04-workspace", "P04 · Project workspace (main screen)", "Composer on the left, takes grid on the right, statuses update live. This is the screen the required features live on.", `
<div class="screen">
  ${appTop("Projects / <b>Lighthouse series</b>", { hold: "$0.3140" })}
  <div class="row" style="align-items:stretch;height:844px">
    <div style="width:420px;background:#fff;border-right:2px solid #bbb;padding:20px 22px;overflow:hidden">
      <div class="row" style="margin-bottom:12px"><b>New take</b> ${n(1)}<span class="grow"></span><span class="xs muted">Remixing #3? It fills this form</span></div>
      ${composer()}
      <div class="xs muted" style="margin-top:10px">${n(2)} Estimate range = docs' min/max text and reasoning tokens. Exact cost comes back when it finishes.</div>
    </div>
    <div class="grow" style="padding:20px 24px">
      <div class="row"><div style="font-size:22px;font-weight:800">Lighthouse series</div><span class="xs muted">8 takes · $0.3525 spent</span><span class="grow"></span>
        <div class="seg" style="width:420px"><span class="on">All 8</span><span>In progress 3</span><span>Done 4</span><span>Failed 1</span></div> ${n(3)}</div>
      <div class="grid" style="grid-template-columns:repeat(4,1fr);margin-top:16px">
        ${takeCard({ state: "pending", size: "1536×1024", prompt: "Lighthouse at dusk, warm light, cinematic wide shot", money: `<span class="muted">Estimate $0.0856 – $0.1139</span>`, extra: "Image service is busy · retrying in 5 s · not charged yet" })}
        ${takeCard({ state: "processing", size: "1024×1536", prompt: "Same lighthouse, portrait, stormy sea", money: `<span class="muted">Estimate $0.0856 – $0.1139</span>`, extra: "Started 12 s ago · usually 5–30 s" })}
        ${takeCard({ state: "queued", size: "1024×1024", prompt: "Night version with stars", money: `<span class="muted">Estimate $0.0581 – $0.0863</span>`, extra: "In the queue" })}
        ${takeCard({ state: "succeeded", img: "take #10", size: "1024×1024", prompt: "Lighthouse at dusk, warm light", money: `<b title=\"$0.0740375\">$0.0740 charged</b><span class="grow"></span><span class="muted">est. $0.0677–$0.0960</span>` })}
        ${takeCard({ state: "failed", size: "1024×1024", prompt: "Lighthouse [fail]", money: `<b class="ok">Not charged</b>`, extra: "The provider couldn't create this image." })}
        ${takeCard({ state: "succeeded", img: "take #8 · remix of #6", size: "1536×1024", prompt: "Warmer light, low sun", money: `<b>$0.1077 charged</b>` })}
        ${takeCard({ state: "succeeded", img: "take #7", size: "1024×1024", prompt: "Black and white version", money: `<b>$0.0691 charged</b>` })}
        ${takeCard({ state: "succeeded", img: "take #6", size: "1024×1536", prompt: "Portrait crop, fog", money: `<b>$0.1013 charged</b>` })}
      </div>
      <div class="row small muted" style="margin-top:12px">${n(4)} Click a take to open it · hover: Remix · Describe a change · Download</div>
    </div>
  </div>
  <div class="toast" style="right:24px;bottom:24px;width:360px"><b class="ok">Take #10 is ready</b><div class="small">Charged $0.0740 (estimate was up to $0.0960). The rest of the hold went back to your balance.</div></div>
</div>`, [
  "One composer for new takes and remixes. Remix pre-fills it from a take (prompt, size, quality, and the take's image as reference).",
  "The estimate updates as you type (debounced 250 ms, server-computed). The button repeats the max so nobody is surprised.",
  "Status filter counts come from the same query as the grid. Live via Reverb; if the socket drops, the header shows <i>Reconnecting · updating every 4 s</i> (polling fallback).",
  "Every card states money in the user's words: <i>Estimate</i> before, <i>$X charged</i> after, <i>Not charged</i> on failure. Pending shows why (capacity/rate limit) instead of a silent spinner.",
  "The toast explains the hold release: actual vs estimate, so the balance change is never a mystery.",
]);

// P05 Composer states
add("p05-composer-states", "P05 · Composer states and errors", "Every failure path ends in a clear sentence and, when relevant, \"you were not charged\".", `
<div class="screen" style="padding:26px;display:grid;grid-template-columns:repeat(2,1fr);gap:22px;background:#f2f2f2">
  <div class="box pad col"><div class="lbl">A · Insufficient balance ${n(1)}</div>
    <div class="est"><div class="big">$0.3577 – $0.4674</div><div class="small">This take can cost up to <b>$0.4674</b>. You have <b>$0.0100</b> available.</div></div>
    <div class="row"><span class="btn p lg d">Generate take · up to $0.4674</span><span class="btn g lg">Buy credits</span></div>
    <div class="xs muted">We check the maximum, not the average, so a take can never leave you below zero.</div></div>
  <div class="box pad col"><div class="lbl">B · Validation (inline, before any request) ${n(2)}</div>
    <div class="inp ta" style="border-color:#c62828">…</div><div class="small bad">Prompt is too long: 4,212 / 4,000 characters.</div>
    <div class="inp" style="border-color:#c62828">ftp://images.example.com/a.png</div><div class="small bad">Reference images must be http(s) URLs.</div>
    <div class="xs muted">Same rules as Pixelforge (max 4 refs, 4,000 chars), validated server-side by a FormRequest.</div></div>
  <div class="box pad col"><div class="lbl">C · Rejected by the provider ${n(3)}</div>
    ${takeCard({ state: "failed", size: "1024×1024", prompt: "[blocked] something", money: `<b class="ok">Not charged</b>`, extra: "Your prompt was rejected by the content policy." })}</div>
  <div class="box pad col"><div class="lbl">D · Busy provider / rate limit ${n(4)}</div>
    ${takeCard({ state: "waiting", size: "1024×1024", prompt: "Lighthouse at dawn", money: `<span class="muted">Estimate $0.0581 – $0.0863 · on hold</span>`, extra: "The image service is at capacity. We'll retry automatically in 5 s." })}
    <div class="xs muted">After 10 min of retries: Failed · "The image service is busy, please try again" · Not charged · hold released.</div></div>
</div>`, [
  "Blocking uses the <b>max</b> estimate (hold), so concurrent takes can't overspend. The CTA turns into <i>Buy credits</i>.",
  "Client and server validation mirror the provider's limits; we never send a request we know will 422.",
  "400 content policy / daily limit = immediate failure, nothing charged, plain-language reason.",
  "429/503 are retried with the same idempotency key, honoring Retry-After. The user sees the reason, not a frozen spinner.",
]);

// P06 Take detail
add("p06-take-detail", "P06 · Take detail (drawer)", "Everything about one take: image, parameters, live timeline, exact cost breakdown and its version lineage.", `
<div class="screen">
  ${appTop("Projects / Lighthouse series / <b>Take #10</b>")}
  <div class="overlay" style="top:56px"></div>
  <div class="drawer" style="top:56px;width:1100px;padding:22px 26px;display:flex;gap:24px">
    <div class="col" style="width:560px">
      ${ph("take #10 · 1024×1024 PNG", 520)}
      <div class="row"><span class="btn p">Remix ${n(1)}</span><span class="btn">Describe a change ${n(2)}</span><span class="btn">Download</span><span class="btn">Copy prompt</span></div>
      <div class="lbl" style="margin-top:8px">Versions ${n(3)}</div>
      <div class="row">${ph("#6 original", 70, "width:92px")}<span>→</span>${ph("#8 warmer", 70, "width:92px")}<span>→</span><div style="outline:3px solid #222;border-radius:8px">${ph("#10 this", 70, "width:92px")}</div><span>→</span>${ph("#11 · #12", 70, "width:92px")}</div>
    </div>
    <div class="col grow">
      <div class="row"><div style="font-size:22px;font-weight:800">Take #10</div>${badge("succeeded")}<span class="grow"></span><span class="btn s">✕</span></div>
      <div class="box pad small">"Lighthouse at dusk, warm light, cinematic wide shot"<div class="row xs muted" style="margin-top:6px">Square 1024×1024 · Medium · 1 reference · AI change from #8</div></div>
      <div class="lbl">Timeline ${n(4)}</div>
      <table class="tbl"><tr><td>12:01:03</td><td>Created · up to $0.0960 held</td></tr><tr><td>12:01:04</td><td>Queued</td></tr><tr><td>12:01:09</td><td>Processing</td></tr><tr><td>12:01:31</td><td>Done · charged $0.0740 · rest of the hold released</td></tr></table>
      <div class="lbl">Price ${n(5)}</div><div class="box pad"><div class="row"><span class="big">$0.0740375</span><span class="badge b-s">charged</span><span class="grow"></span><span class="small muted">estimate was $0.0677 – $0.0960</span></div><div class="small muted" style="margin-top:6px">Up to $0.0960 was held while it ran; the rest went back to your balance.</div></div><div class="xs muted">Image kept in your project (we store a copy; provider links expire in 24 h).</div>
    </div>
  </div>
</div>`, [
  "Remix = open the composer pre-filled with this take as parent; the new take records <code>parent_take_id</code>.",
  "Describe a change starts the AI flow (P07) with this take as the base.",
  "Version strip = ancestors and children (lineage), the video-editing \"history\" metaphor.",
  "Timeline comes from state transitions + ledger entries; it is the same data the admin sees, simplified.",
  "Required by the brief: estimated and actual cost per take. Here the actual is shown exact ($0.0740375; lists show 4 decimals with the exact value on hover). Customers never see provider cost, tokens or margin (D-023). The full breakdown lives in the admin take view (A06). Numbers are computed with the real Pixelforge rates.",
]);

// P07 Describe a change
add("p07-describe-a-change", "P07 · Describe a change (AI, Haiku 5.5)", "Natural language → a parameter diff with a new price → the user confirms. The AI never spends Pixelforge money on its own.", `
<div class="screen" style="padding:22px;display:grid;grid-template-columns:1fr 1.25fr 1fr;gap:20px;background:#f2f2f2">
  <div class="box pad col"><div class="lbl">Step 1 · Ask ${n(1)}</div>
    ${ph("base: take #10", 220)}
    <div><div class="lbl">What should change?</div><div class="inp ta" style="min-height:90px">Make it portrait, golden hour, more detail on the waves</div><div class="xs muted" style="margin-top:4px">62 / 500</div></div>
    <div class="row" style="flex-wrap:wrap"><span class="pill">warmer light</span><span class="pill">make it portrait</span><span class="pill">more detail</span><span class="pill">black & white</span></div>
    <div class="est"><div class="small">AI suggestion · about <b>$0.0003</b> (max $0.0007) ${n(2)}</div><div class="xs muted">Charged even if you discard the suggestion.</div></div>
    <span class="btn p lg">Suggest change</span></div>
  <div class="box pad col"><div class="lbl">Step 2 · Review the proposal ${n(3)}</div>
    <div class="small"><b>Prompt</b></div>
    <div class="box pad small">Lighthouse at <span class="diff-del">dusk, warm light</span><span class="diff-add">golden hour, low sun</span>, cinematic <span class="diff-del">wide shot</span><span class="diff-add">portrait shot, detailed crashing waves</span></div>
    <table class="tbl"><tr><th>Setting</th><th>Before</th><th>After</th></tr><tr><td>Size</td><td>Square 1024×1024</td><td class="diff-add">Portrait 1024×1536</td></tr><tr><td>Quality</td><td>Medium</td><td class="diff-add">High</td></tr><tr><td>References</td><td>1</td><td>1 + take #10 image</td></tr></table>
    <div class="small muted">"Switched to portrait and high quality for wave detail; kept your composition." ${n(4)}</div>
    <div class="est"><div class="small">Suggestion cost: <b>$0.0003125</b> charged</div><div class="big" style="margin-top:6px">New take: $0.3573 – $0.4668</div></div>
    <div class="row"><span class="btn p lg">Generate take · up to $0.4668</span><span class="btn">Edit in composer</span><span class="btn">Discard</span></div></div>
  <div class="box pad col"><div class="lbl">Step 3 · Result ${n(5)}</div>
    ${takeCard({ state: "processing", size: "1024×1536", prompt: "Lighthouse at golden hour, low sun, cinematic portrait…", money: `<span class="muted">Estimate $0.3573 – $0.4668</span>`, extra: "Changed from #10 by AI" })}
    <div class="box pad small" style="border-style:dashed"><b>Limits</b><br>30 suggestions per customer per day · instruction ≤ 500 chars · global daily AI budget; when reached: "AI suggestions are paused for today. You can still edit manually."</div></div>
</div>`, [
  "Single-shot call (no chat): base take parameters + instruction in, structured proposal out. Cheap, explainable, testable.",
  "AI calls are billed like takes: hold the max (max_tokens bound), settle the exact Anthropic usage + 25% in the <b>same multi-provider ledger</b>.",
  "The proposal is a diff, never an automatic action. The user sees what changes and the new price before anything runs.",
  "The model explains its change in one sentence (structured output field <code>rationale</code>).",
  "The generated take keeps the lineage (<i>changed from #10 by AI</i>) and links to the suggestion that produced it.",
]);

// P08 Billing
add("p08-billing", "P08 · Credits & billing", "Available balance, holds, spend by feature, purchases and invoices. Payments are simulated and labelled as such.", `
<div class="screen">
  ${appTop("<b>Billing</b>")}
  <div style="padding:22px 30px" class="col">
    <div class="row" style="gap:16px">
      <div class="box pad grow"><div class="lbl">Available ${n(1)}</div><div class="huge">$10.33358125</div><div class="xs muted">balance $10.64754375 − on hold $0.3139625</div></div>
      <div class="box pad grow"><div class="lbl">Spent in November ${n(2)}</div><div class="huge">$0.35245625</div><div class="xs muted">Images $0.35214375 · AI suggestions $0.0003125</div></div>
      <div class="box pad grow col" style="gap:8px"><div class="lbl">Buy credits ${n(3)}</div><div class="row"><span class="btn">$5</span><span class="btn p">$10</span><span class="btn">$25</span></div><div class="xs muted">Simulated payment · creates a paid invoice</div></div>
    </div>
    <div class="tabs"><span class="on">Activity</span><span>Invoices</span></div>
    <table class="tbl">
      <tr><th>Date (UTC)</th><th>Description</th><th>Type</th><th class="r">Amount</th><th class="r">Balance</th></tr>
      <tr><td>Nov 17 12:01</td><td>Take #10 · charged (up to $0.0960 was held)</td><td>Image</td><td class="r">−$0.0740375</td><td class="r">$10.64754375</td></tr>
      <tr><td>Nov 17 11:58</td><td>AI suggestion for take #8</td><td>AI suggestion</td><td class="r">−$0.0003125</td><td class="r">$10.72158125</td></tr>
      <tr><td>Nov 17 11:40</td><td>Take #9 · failed · not charged</td><td>Image</td><td class="r">$0.00</td><td class="r">$10.72189375</td></tr>
      <tr><td>Nov 17 10:02</td><td>Credits purchased · invoice INV-2026-000004 ${n(4)}</td><td>Purchase</td><td class="r ok">+$10.00</td><td class="r">$10.72189375</td></tr>
      <tr><td>Nov 16 18:52</td><td>Take #8 · charged</td><td>Image</td><td class="r">−$0.107725</td><td class="r">$0.72189375</td></tr><tr><td colspan="5" class="muted">Show 3 earlier entries (takes #6, #7, welcome credit +$1.00)</td></tr>
    </table>
    <div class="xs muted">${n(5)} Holds are state, not ledger lines (D-024): they show as "on hold" until the take settles. This is a money page, so amounts are exact (D-042).</div>
  </div>
  <div class="overlay"></div>
  <div class="modal" style="left:470px;top:190px;width:500px;padding:22px" ><div class="row"><div class="big">Buy $10.00 of credits</div><span class="grow"></span><span class="btn s">✕</span></div>
    <div class="box pad small" style="margin-top:12px;border-style:dashed">This is a <b>simulated payment</b> for the take-home. No card, nothing is charged.</div>
    <table class="tbl" style="margin-top:12px"><tr><td>Takes credits</td><td class="r">$10.00</td></tr><tr><td>Tax</td><td class="r">$0.00</td></tr><tr class="tot"><td>Total</td><td class="r">$10.00</td></tr></table>
    <div class="row" style="margin-top:14px"><span class="grow"></span><span class="btn">Cancel</span><span class="btn p lg">Pay $10.00 (simulated)</span></div></div>
</div>`, [
  "Available = balance − active holds. The same formula blocks generation (P05-A).",
  "Spend split by feature (images, AI suggestions) comes from the multi-provider usage records; provider names and costs stay internal (D-023).",
  "Fixed packages keep it simple; no card fields at all, so nothing looks like a real payment form.",
  "Each purchase creates a sequential invoice (gapless numbering per year).",
  "Activity = ledger entries (money only) plus failed takes as $0.00 lines so the customer sees they weren't charged.",
]);

// P09 Invoice + statement
add("p09-invoice", "P09 · Invoice (printable) and invoices list", "Simple invoicing: each simulated purchase produces one numbered invoice. Print-friendly HTML, no PDF library. No monthly statements (D-029).", `
<div class="screen" style="padding:26px;display:grid;grid-template-columns:1fr 1fr;gap:26px;background:#f2f2f2">
  <div class="box" style="padding:34px">
    <div class="row"><div class="logo"><b></b>Takes</div><span class="grow"></span><div class="col" style="gap:2px;text-align:right"><b style="font-size:22px">Invoice ${n(1)}</b><span>INV-2026-000004</span><span class="small muted">Issued Nov 17, 2026 · Paid</span></div></div>
    <div class="row" style="margin-top:26px;align-items:flex-start"><div class="col small" style="gap:2px"><b>Billed to</b><span>Ana López</span><span>ana@example.com</span></div><span class="grow"></span><div class="col small" style="gap:2px;text-align:right"><b>From</b><span>Takes (demo)</span><span>Simulated merchant</span></div></div>
    <table class="tbl" style="margin-top:24px"><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit</th><th class="r">Amount</th></tr><tr><td>Takes credits (prepaid usage)</td><td class="r">1</td><td class="r">$10.00</td><td class="r">$10.00</td></tr><tr><td>Tax (not applicable in demo)</td><td></td><td></td><td class="r">$0.00</td></tr><tr class="tot"><td colspan="3">Total paid</td><td class="r">$10.00</td></tr></table>
    <div class="small muted" style="margin-top:16px">Payment: simulated · ref SIM-01JD8X… · ${n(2)} Credits do not expire.</div>
    <div class="row" style="margin-top:20px"><span class="btn">Print / Save as PDF</span></div>
  </div>
  <div class="box" style="padding:34px">
    <div class="big">Billing › Invoices ${n(3)}</div>
    <table class="tbl" style="margin-top:20px"><tr><th>Number</th><th>Issued</th><th class="r">Total</th><th>Status</th><th></th></tr>
    <tr><td>INV-2026-000004</td><td>Nov 17, 2026</td><td class="r">$10.00</td><td><span class="badge b-s">paid</span></td><td><span class="btn s">View</span></td></tr></table>
    <div class="small muted" style="margin-top:14px">${n(4)} Welcome credit is not a sale, so it has no invoice. Usage is not invoiced again: it is paid from prepaid credits and listed in Activity.</div>
  </div>
</div>`, [
  "Invoices are created only for purchases (money in). Numbering is gapless per year via a locked counter row.",
  "Prepaid credits model: usage is not invoiced again (it was paid for upfront); it appears on the statement.",
  "Invoices tab lists every purchase invoice.",
  "Prepaid model: only money-in is invoiced (D-022, D-029).",
]);

// P10 Mobile
add("p10-mobile", "P10 · Workspace at phone width (390 px)", "Same screen, stacked: grid first, composer as a bottom sheet.", `
<div class="row" style="gap:40px;align-items:flex-start">
<div class="screen m">
  <div class="top" style="padding:0 14px"><div class="logo"><b></b>Takes</div><div class="grow"></div><span class="pill small">$10.33</span></div>
  <div style="padding:12px"><div class="row"><b>Lighthouse series</b><span class="grow"></span><span class="live">Live</span></div>
  <div class="seg small" style="margin-top:8px"><span class="on">All</span><span>Running 2</span><span>Done</span><span>Failed</span></div>
  <div class="grid" style="grid-template-columns:1fr 1fr;margin-top:10px">${ph("#10", 150)}${ph("#8", 150)}<div class="ph" style="height:150px;background:#efefef"><span>Processing</span></div>${ph("#7", 150)}</div></div>
  <div style="position:absolute;left:0;right:0;bottom:0;background:#fff;border-top:3px solid #444;border-radius:18px 18px 0 0;padding:14px" class="col"><div style="width:50px;height:5px;background:#bbb;border-radius:3px;align-self:center"></div><div class="inp small">Describe your take…</div><div class="row"><span class="pill small">▢ Square</span><span class="pill small">Medium</span><span class="pill small">+ Ref</span></div><span class="btn p">Generate · up to $0.0960 ${n(1)}</span></div>
</div>
<div class="screen m">
  <div class="top" style="padding:0 14px"><span>←</span><b>Take #10</b><div class="grow"></div>${badge("succeeded")}</div>
  <div style="padding:12px" class="col">${ph("take #10", 330)}<div class="row"><span class="btn p s">Remix</span><span class="btn s">Describe a change</span><span class="btn s">⤓</span></div><div class="box pad small"><b>$0.0740 charged</b><br><span class="muted">estimate was $0.0677 – $0.0960</span></div></div>
</div></div>`, [
  "The composer collapses to a bottom sheet; the max price stays on the button at every width. 16 px gutters, no horizontal scroll.",
]);

// ---------- admin chrome ----------
const nav = (active) => {
  const items = [["Dashboard"], ["Customers"], ["g", "Operations"], ["Studio"], ["Billing"], ["Providers"], ["g", "System"], ["Users (admins)"], ["Roles"], ["Activity log"]];
  return `<div class="side"><div class="logo" style="padding:4px 8px 12px"><b></b>Takes <span class="xs muted">console</span></div>${items.map((i) => i[0] === "g" ? `<div class="grp">${i[1]}</div>` : `<div class="it ${i[0] === active ? "on" : ""}"><span class="ico"></span>${i[0]}${i[0] === "Studio" ? `<span class="grow"></span><span class="badge b-p">2</span>` : ""}${i[0] === "Providers" ? `<span class="grow"></span><span class="badge b-f">1</span>` : ""}</div>`).join("")}</div>`;
};
const admin = (active, crumbs, body, opts = {}) => `
<div class="screen"><div class="adm"><div class="env"></div>${nav(active)}<div class="amain">
  <div class="atop"><span class="bc">${crumbs}</span><div class="grow"></div><span class="envb">PRODUCTION</span><div class="inp" style="width:220px;min-height:34px;padding:6px 10px" ><span class="small muted">Search…</span></div><div class="ph" style="width:32px;height:32px;border-radius:16px"></div></div>
  <div class="abody">${body}</div></div></div></div>`;

// A02 Dashboard
add("a02-admin-dashboard", "A02 · Console dashboard", "Clusters in the sidebar (Studio, Billing, Providers) with the order defined once in the panel's navigation builder. ECharts for the money story.", admin("Dashboard", "Dashboard", `
  <div class="row"><div class="h2">Dashboard</div><span class="grow"></span><div class="seg" style="width:320px"><span>Today</span><span class="on">30 days</span><span>Month</span></div> ${n(1)}</div>
  <div class="row" style="gap:12px">
    ${[["Customer spend", "$41.2210"], ["Provider cost", "$32.9768"], ["Profit", "$8.2442"], ["Margin", "20.0%"], ["Takes", "812 · 4.1% failed"], ["Running now", "2"]].map(([a, b]) => `<div class="stat"><div class="lbl">${a}</div><div class="v">${b}</div></div>`).join("")}
  </div>
  <div class="row" style="gap:14px;align-items:stretch">
    <div class="chart grow"><div class="row"><b>Revenue, cost and profit per day (UTC)</b> ${n(2)}<span class="grow"></span><span class="xs muted">ECharts line</span></div>
      <svg width="100%" height="250" viewBox="0 0 700 250"><polyline fill="none" stroke="#222" stroke-width="3" points="0,180 60,160 120,170 180,120 240,130 300,90 360,110 420,70 480,95 540,60 600,80 700,40"/><polyline fill="none" stroke="#888" stroke-width="3" points="0,200 60,185 120,192 180,150 240,158 300,125 360,140 420,108 480,128 540,98 600,115 700,82"/><polyline fill="none" stroke="#2e7d32" stroke-width="3" stroke-dasharray="6 4" points="0,235 60,232 120,233 180,226 240,228 300,222 360,225 420,218 480,222 540,216 600,219 700,212"/></svg>
      <div class="row xs muted"><span>— customer spend</span><span>— provider cost</span><span class="ok">- - profit</span></div></div>
    <div class="chart" style="width:330px"><b>Provider cost split</b> ${n(3)}<svg width="100%" height="220" viewBox="0 0 220 220"><circle cx="110" cy="110" r="70" fill="none" stroke="#999" stroke-width="38"/><circle cx="110" cy="110" r="70" fill="none" stroke="#222" stroke-width="38" stroke-dasharray="400 440" transform="rotate(-90 110 110)"/></svg><div class="xs">■ Pixelforge 99.9% · □ Anthropic 0.1%</div></div>
  </div>
  <div class="chart"><div class="row"><b>Needs attention</b> ${n(4)}<span class="grow"></span><span class="xs muted">table widget</span></div>
    <table class="tbl"><tr><th>What</th><th>Detail</th><th></th></tr><tr><td>${badge("waiting")}</td><td>Take 01JD9… pending for 12 min (rate limited)</td><td><span class="btn s">Open</span></td></tr><tr><td><span class="badge b-f">Mismatch</span></td><td>Pixelforge usage Nov 15: ours $2.391100 vs provider $2.415975</td><td><span class="btn s">Reconcile</span></td></tr></table></div>
`), [
  "Range filter shared by all widgets (Filament page filters). No polling on widgets (<code>$pollingInterval = null</code>) so the database can sleep on Laravel Cloud.",
  "<code>elemind/filament-echarts</code> line chart from the usage records grouped by billing day (UTC completion day).",
  "Cost split by provider = the multi-provider ledger made visible.",
  "Operational list: stuck takes, failed webhook outcomes, reconciliation mismatches; each links to the right cluster page.",
]);

// A03 Billing reports
add("a03-admin-billing-reports", "A03 · Billing cluster › Reports", "Stretch goal 1: per day and per customer, customer spend vs provider cost vs profit.", admin("Billing", "Billing › Reports", `
  <div class="row"><div class="h2">Billing</div></div>
  <div class="tabs"><span class="on">Reports ${n(1)}</span><span>Ledger</span><span>Purchases</span><span>Invoices</span></div>
  <div class="row"><div class="inp" style="width:300px">Nov 1, 2026 → Nov 17, 2026 (UTC)</div><div class="seg" style="width:260px"><span class="on">By day</span><span>By customer</span></div> ${n(2)}<div class="seg" style="width:300px"><span class="on">All</span><span>Pixelforge</span><span>Anthropic</span></div><span class="grow"></span><span class="btn">Export CSV</span></div>
  <table class="tbl">
    <tr><th>Day (UTC)</th><th class="r">Takes</th><th class="r">AI suggestions</th><th class="r">Customer spend</th><th class="r">Pixelforge cost</th><th class="r">Anthropic cost</th><th class="r">Profit</th><th class="r">Margin</th></tr>
    ${[["Nov 17", 41, 9, "2.0531", "1.6402", "0.0023", "0.4106"], ["Nov 16", 77, 15, "3.8437", "3.0712", "0.0038", "0.7687"], ["Nov 15", 64, 4, "2.9901", "2.3911", "0.0010", "0.5980"]].map((r) => `<tr><td>${r[0]}</td><td class="r">${r[1]}</td><td class="r">${r[2]}</td><td class="r">$${r[3]}</td><td class="r">$${r[4]}</td><td class="r">$${r[5]}</td><td class="r">$${r[6]}</td><td class="r">20.0%</td></tr>`).join("")}
    <tr class="tot"><td>Total</td><td class="r">182</td><td class="r">28</td><td class="r">$8.8869</td><td class="r">$7.1025</td><td class="r">$0.0070</td><td class="r">$1.7774</td><td class="r">20.0%</td></tr>
  </table>
  <div class="small muted">${n(3)} Profit = customer spend − provider cost. With exact 25% markup, margin on spend is always 20.0%; anything else signals a bug, so the column is a built-in sanity check.</div>
`), [
  "Billing is a Filament cluster with top sub-navigation, like Buxy's BillingCluster.",
  "Group by day or by customer; provider filter. Aggregates in SQL on bigint nanodollars, formatted only at render.",
  "Margin column doubles as an invariant check (25% markup ⇒ 20% of revenue).",
]);

// A04 Providers reconciliation
add("a04-admin-providers", "A04 · Providers cluster › Pixelforge reconciliation", "Proves provider costs match GET /v1/usage, per UTC day.", admin("Providers", "Providers › Pixelforge usage", `
  <div class="row"><div class="h2">Providers</div></div>
  <div class="tabs"><span class="on">Pixelforge usage</span><span>Anthropic usage ${n(1)}</span><span>Webhook events</span><span>Pricing</span></div>
  <div class="row"><div class="inp" style="width:300px">Oct 19 → Nov 17, 2026 (UTC, ≤ 92 days)</div><span class="grow"></span><span class="badge b-s" style="font-size:14px">28 of 29 closed days match</span><span class="btn">Refresh from API ${n(2)}</span></div>
  <table class="tbl">
    <tr><th>Day (UTC)</th><th class="r">Generations (ours / theirs)</th><th class="r">Our provider cost</th><th class="r">Pixelforge cost_usd</th><th class="r">Difference</th><th>Status</th></tr>
    <tr><td>Nov 17 (today)</td><td class="r">39 / 39</td><td class="r">$1.640200000</td><td class="r">$1.640200000</td><td class="r">$0.000000000</td><td><span class="badge b-q">Pending · 3 in flight</span></td></tr>
    <tr><td>Nov 16</td><td class="r">73 / 73</td><td class="r">$3.071200000</td><td class="r">$3.071200000</td><td class="r">$0.000000000</td><td><span class="badge b-s">Match</span></td></tr>
    <tr><td>Nov 15</td><td class="r">60 / 61</td><td class="r">$2.391100000</td><td class="r">$2.415975000</td><td class="r bad">−$0.024875000</td><td><span class="badge b-f">Mismatch</span> <span class="btn s">Find take ${n(3)}</span></td></tr>
  </table>
  <div class="small muted">Billing day = the UTC day the generation <b>completed</b> (provider's completed_at), same rule as Pixelforge. 9 decimals here because this page exists to compare exact values.</div>
`), [
  "Anthropic tab lists our recorded token usage per day/model; the Anthropic console is the external reference (Admin API needs an admin key we don't hold).",
  "Calls GET /v1/usage for the range (cached 5 min). Read-only, safe for the demo auditor role.",
  "Mismatch drill-down lists takes completed that day that we don't have settled (e.g. still waiting for a webhook) with a <i>Reconcile now</i> action.",
]);

// A05 Customer view
add("a05-admin-customer", "A05 · Customers › View customer", "One place to understand a customer's money: balance, holds, takes, ledger, invoices, AI usage.", admin("Customers", "Customers › Ana López", `
  <div class="row"><div class="h2">Ana López</div><span class="small muted">ana@example.com · joined Nov 16</span><span class="grow"></span><span class="btn">Adjust credits ${n(1)}</span></div>
  <div class="row" style="gap:12px">${[["Available", "$10.33358125"], ["On hold", "$0.3139625"], ["Lifetime spend", "$0.35245625"], ["Profit generated", "$0.07049125"], ["Takes", "8 · 1 failed"]].map(([a, b]) => `<div class="stat"><div class="lbl">${a}</div><div class="v">${b}</div></div>`).join("")}</div>
  <div class="tabs"><span>Takes</span><span class="on">Ledger ${n(2)}</span><span>Invoices</span><span>AI suggestions</span><span>Projects</span></div>
  <table class="tbl"><tr><th>When (UTC)</th><th>Type</th><th>Reference</th><th class="r">Amount (nanodollars)</th><th class="r">Amount</th></tr>
  <tr><td>Nov 17 12:01:31</td><td><span class="badge b-s">charge</span></td><td>take #10 · pixelforge</td><td class="r">−74,037,500</td><td class="r">−$0.0740375</td></tr><tr><td>Nov 17 11:58:02</td><td><span class="badge b-s">charge</span></td><td>suggestion on #8 · anthropic</td><td class="r">−312,500</td><td class="r">−$0.0003125</td></tr><tr><td>Nov 17 10:02:11</td><td><span class="badge b-s">purchase</span></td><td>INV-2026-000004</td><td class="r">+10,000,000,000</td><td class="r">+$10.00</td></tr><tr><td>Nov 16 18:52:40</td><td><span class="badge b-s">charge</span></td><td>take #8 · pixelforge</td><td class="r">−107,725,000</td><td class="r">−$0.107725</td></tr></table>
  <div class="overlay"></div>
  <div class="modal" style="left:560px;top:250px;width:470px;padding:22px"><div class="big">Adjust credits</div><div class="col" style="margin-top:12px"><div><div class="lbl">Amount (USD, + or −)</div><div class="inp">+2.00</div></div><div><div class="lbl">Reason (required, audited) ${n(3)}</div><div class="inp ta" style="min-height:70px">Refund for broken reference image, ticket #12</div></div><div class="row"><span class="grow"></span><span class="btn">Cancel</span><span class="btn p">Apply adjustment</span></div></div></div>
`), [
  "Header action gated by a Shield permission (<code>adjust_credits_customer</code>); hidden for the demo auditor.",
  "Relation managers per tab. The ledger holds money only (holds are open usage records, D-024); nanodollars next to dollars: the admin is where exactness is visible.",
  "Creates an <code>adjustment</code> ledger entry and an activity-log record with the admin and the reason.",
]);

// A06 Take view
add("a06-admin-take", "A06 · Studio cluster › Take view", "Debugging a single take: provider payload, state transitions and every webhook delivery with its outcome.", admin("Studio", "Studio › Takes › 01JD9…X2", `
  <div class="row"><div class="h2">Take 01JD9…X2</div>${badge("succeeded")}<span class="grow"></span><span class="btn">Reconcile now ${n(1)}</span><span class="btn">Open customer</span></div>
  <div class="tabs"><span class="on">Takes</span><span>Projects</span><span>AI suggestions</span></div>
  <div class="row" style="align-items:flex-start;gap:16px">
    <div class="col" style="width:300px">${ph("image", 260)}<div class="box pad small">gen_8f2… · idempotency key 6c1e… · Square · Medium · 1 ref · parent: #8</div></div>
    <div class="col grow">
      <div class="chart"><b>Money ${n(2)}</b><table class="tbl" style="margin-top:8px"><tr><td>Estimate (min / max cost)</td><td class="r">54,215,000 / 76,745,000</td></tr><tr><td>Tokens (text / image / output / reasoning)</td><td class="r">16 / 765 / 1,100 / 600</td></tr><tr><td>Actual provider cost</td><td class="r">59,230,000</td></tr><tr><td>Charge (cost × 125 / 100)</td><td class="r">74,037,500</td></tr><tr><td>Rates snapshot (nano per 1M tokens)</td><td class="r">text 5e9 · image 1e10 · output 4e10 · reasoning 1.25e10</td></tr></table></div>
      <div class="chart"><b>Webhook deliveries ${n(3)}</b><table class="tbl" style="margin-top:8px"><tr><th>Received</th><th>Event</th><th>Type</th><th>Attempt</th><th>Outcome</th></tr>
        <tr><td>12:01:09</td><td>evt_a1…</td><td>generation.processing</td><td>1</td><td><span class="badge b-s">applied</span></td></tr>
        <tr><td>12:01:31</td><td>evt_b7…</td><td>generation.succeeded</td><td>1</td><td><span class="badge b-s">applied</span></td></tr>
        <tr><td>12:01:32</td><td>evt_b7…</td><td>generation.succeeded</td><td>2</td><td><span class="badge b-q">duplicate</span></td></tr>
        <tr><td>12:01:40</td><td>evt_a1…</td><td>generation.processing</td><td>2</td><td><span class="badge b-q">duplicate</span></td></tr>
        <tr><td>12:02:10</td><td>evt_c9…</td><td>generation.processing</td><td>1</td><td><span class="badge b-p">ignored_stale</span></td></tr></table></div>
    </div>
  </div>
`), [
  "Runs the same reconcile action the polling job uses (GET /v1/generations/{id} → apply snapshot). Idempotent.",
  "Raw integers on purpose: this is where we prove exactness during the interview.",
  "Duplicates and late events are visible with their outcome, which is the evidence for the webhook requirements.",
]);

// A07 Navigation map
add("a07-admin-navigation", "A07 · Console information architecture", "Clusters, resources and permissions at a glance (the Filament structure we will build).", `
<div class="screen" style="padding:30px;background:#fff">
  <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:20px">
    ${[
      ["Top level", ["Dashboard (stats, ECharts, needs attention)", "Customers resource: list, view (A05), adjust credits"]],
      ["Studio cluster", ["Takes resource (list + view A06, read-only)", "Projects resource (read-only)", "AI suggestions resource (read-only)"]],
      ["Billing cluster", ["Reports page (A03)", "Ledger entries resource", "Purchases resource", "Invoices resource (+ print view)"]],
      ["Providers cluster", ["Pixelforge usage page (A04)", "Anthropic usage page", "Webhook events resource", "Pricing page (current /v1/pricing + snapshots)"]],
      ["System group", ["Users resource = admins (default users table, web guard, MFA status)", "Roles (Shield, web guard)", "Customer access page (customer-guard roles, see A08)", "Activity log (rmsramos/activitylog)"]],
      ["Roles", ["web: super_admin · operator · auditor (demo, read-only, MFA-exempt)", "customer: customer · restricted", "Details and permission names: A08"]],
      ["Plugins", ["bezhansalleh/filament-shield", "elemind/filament-echarts", "rmsramos/activitylog + spatie/laravel-activitylog", "pxlrbt/filament-environment-indicator"]],
      ["Panel conventions (Buxy)", ["navigation() builder: one place for order", "spa(), sidebarCollapsibleOnDesktop()", "breadcrumbs(hasNavigationHierarchy: true)", "Resources split into Tables/ Schemas/ Actions/ Pages/"]],
    ].map(([h, items]) => `<div class="box pad"><div class="lbl">${h}</div><ul style="margin:6px 0 0;padding-left:18px;line-height:1.6">${items.map((i) => `<li>${i}</li>`).join("")}</ul></div>`).join("")}
  </div>
  <div class="box pad" style="margin-top:20px;border-style:dashed">${n(1)} Every write action in the console (adjust credits, reconcile, role changes) is recorded in the activity log with the admin, as Buxy's RecordAdminWrites does.</div>
</div>`, [
  "Read-mostly console: the only money-changing action is <i>Adjust credits</i>, and it is audited.",
]);


// A08 Authorization model
add("a08-authorization-model", "A08 · Authorization: Shield + spatie/permission on two guards", "Permissions say what a role may do. Credits are state, checked by a policy at the moment of the action. We never revoke a permission because the balance ran out.", `
<div class="screen" style="padding:26px;background:#fff">
  <div class="row" style="align-items:stretch;gap:22px">
    <div class="box pad grow col"><div class="lbl">Guard web · table users = admins ${n(1)}</div>
      <table class="tbl"><tr><th>Role</th><th>Gets</th></tr>
        <tr><td><b>super_admin</b></td><td>Everything (Shield Gate::before). MFA required.</td></tr>
        <tr><td><b>operator</b></td><td>All view permissions + <code>adjust_credits_customer</code>, <code>reconcile_take</code>, <code>restrict_customer</code>. MFA required.</td></tr>
        <tr><td><b>auditor</b></td><td>Only <code>view_any_*</code> / <code>view_*</code> + report pages. MFA-exempt (demo). </td></tr></table>
      <div class="small muted">Shield generates resource/page permissions (<code>view_any_take</code>, <code>page_Reports</code>, …). Custom ones are declared in an enum and seeded, so they exist in every environment.</div></div>
    <div class="box pad grow col"><div class="lbl">Guard customer · table customers ${n(2)}</div>
      <table class="tbl"><tr><th>Permission</th><th>customer</th><th>restricted</th></tr>
        <tr><td><code>takes.create</code></td><td>✓</td><td>✗</td></tr>
        <tr><td><code>ai.suggest</code></td><td>✓</td><td>✗</td></tr>
        <tr><td><code>credits.purchase</code></td><td>✓</td><td>✓</td></tr>
        <tr><td><code>projects.manage</code></td><td>✓</td><td>✓</td></tr></table>
      <div class="small muted">Same spatie tables, <code>guard_name = customer</code>. Every new customer gets the <b>customer</b> role. An operator can switch someone to <b>restricted</b> (abuse), which is a permission change; running out of credits is not.</div></div>
  </div>
  <div class="box pad" style="margin-top:20px"><div class="lbl">Can this customer generate this take? ${n(3)}</div>
    <div class="row" style="gap:10px;flex-wrap:wrap;font-size:14px">
      <span class="pill">FormRequest::authorize()</span><span>→</span>
      <span class="pill">Gate::inspect('create', [Take::class, $estimate])</span><span>→</span>
      <span class="pill">TakePolicy::create</span><span>→</span>
      <span class="pill">1 · has <code>takes.create</code>? else <b>deny: restricted</b></span><span>→</span>
      <span class="pill">2 · available ≥ estimate max? else <b>deny: insufficient_credits</b></span><span>→</span>
      <span class="pill ok">allow</span>
    </div>
    <div class="row" style="gap:10px;margin-top:14px;font-size:14px">
      <span class="pill">CreateTakeAction</span><span>→</span><span class="pill">lockForUpdate(customer)</span><span>→</span><span class="pill">re-check available ≥ max ${n(4)}</span><span>→</span><span class="pill">insert take + open usage (hold)</span><span>→</span><span class="pill">commit</span></div></div>
  <div class="row" style="gap:22px;margin-top:20px;align-items:stretch">
    <div class="box pad grow"><div class="lbl">What the UI receives ${n(5)}</div><div class="inp small" style="font-family:monospace">can: { createTake: false, reason: 'insufficient_credits', needed: '0.4674', available: '0.0100' }</div><div class="small muted" style="margin-top:6px">Shared Inertia prop recomputed with the current estimate; the button shows the reason (P05-A). The server never trusts it.</div></div>
    <div class="box pad grow"><div class="lbl">Same pattern for AI suggestions</div><div class="small"><code>ai.suggest</code> permission → policy checks available ≥ AI max charge, the customer's daily limit and the global AI budget → reasons <code>insufficient_credits</code>, <code>daily_limit</code>, <code>ai_paused</code>.</div></div>
  </div>
</div>`, [
  "Admins use Laravel's default <code>users</code> table and <code>web</code> guard (Filament's default). Shield manages their roles and permissions.",
  "Customers have their own table and guard; spatie/permission supports several guards on the same tables.",
  "Policies return <code>Response::deny($message, $code)</code> so the reason is machine-readable and the UI can show the right fix (buy credits vs contact support).",
  "The policy is the friendly early check; the action is the guarantee. Two simultaneous requests can't both pass because the second waits for the lock and sees the first hold.",
  "Authorization results are shared with the frontend, but only as hints for UX.",
]);


// ---------- resource catalog sheets ----------
const rt = (title, cols, rows, meta) => `
<div class="box pad col" style="gap:8px">
  <div class="row"><b style="font-size:16px">${title}</b><span class="grow"></span>${(meta.badges || []).join("")}</div>
  ${meta.widgets ? `<div class="row" style="gap:8px">${meta.widgets.map((w) => `<div class="stat" style="padding:7px 10px"><div class="lbl" style="font-size:10px">${w[0]}</div><div style="font-weight:800">${w[1]}</div></div>`).join("")}</div>` : ""}
  <table class="tbl"><tr>${cols.map((c) => `<th class="${c.endsWith(" ") ? "r" : ""}">${c.trim()}</th>`).join("")}</tr>${rows.map((r) => `<tr>${r.map((c, i) => `<td class="${cols[i].endsWith(" ") ? "r" : ""}">${c}</td>`).join("")}</tr>`).join("")}</table>
  <div class="xs"><b>Filters:</b> ${meta.filters} · <b>Row actions:</b> ${meta.actions}${meta.header ? ` · <b>Header actions:</b> ${meta.header}` : ""}${meta.sort ? ` · <b>Default sort:</b> ${meta.sort}` : ""}</div>
</div>`;

add("a09-tables-customers-studio", "A09 · Tables: Customers and Studio cluster", "Columns, filters, actions and header widgets of each resource. Money columns are bigint nanodollars formatted at render; counts and sums come from withCount/withSum, never N+1.", `
<div class="screen" style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px;background:#f2f2f2;align-content:start;height:auto;min-height:900px">
  <div style="grid-column:1/3">${rt("Customers", ["Customer", "Role", "Available ", "On hold ", "Lifetime spend ", "Profit ", "Takes ", "Last active", "Joined"], [["Ana López<br><span class='xs muted'>ana@example.com</span>", badge("succeeded").replace("Done", "customer"), "$10.3336", "$0.3140", "$0.3525", "$0.0705", "8", "1 min ago", "Nov 16"], ["Low balance (demo)<br><span class='xs muted'>low@takes.test</span>", badge("succeeded").replace("Done", "customer"), "$0.0100", "$0.0000", "$0.0000", "$0.0000", "0", "1 h ago", "Nov 16"]], { widgets: [["Customers", "148"], ["Active today", "23"], ["Outstanding credits (liability)", "$612.4410"], ["Avg spend / active customer", "$0.3120"]], filters: "role, active in range, available < $0.10, has takes in progress", actions: "View (A05), Adjust credits, Restrict / Unrestrict", sort: "last active desc", badges: [n(1)] })}</div>
  <div style="grid-column:1/3">${rt("Studio › Takes", ["", "Take", "Customer · project", "Status", "Size · quality", "Source", "Est. max ", "Charged ", "Provider cost ", "Duration ", "Created"], [[ph("", 34, "width:34px"), "#10", "Ana · Lighthouse", badge("succeeded"), "1024² · medium", "AI change", "$0.0960", "$0.0740", "$0.0592", "28 s", "12:01"], [ph("", 34, "width:34px"), "#13", "Ana · Lighthouse", badge("waiting"), "1536×1024 · medium", "composer", "$0.1139", "−", "−", "1 min", "12:02"], [ph("", 34, "width:34px"), "#9", "Ana · Lighthouse", badge("failed"), "1024² · low", "composer", "$0.0219", "$0.0000", "$0.0000", "19 s", "11:40"]], { widgets: [["In progress", "3"], ["Stuck > 15 min", "0"], ["Failure rate (30 d)", "3.8%"], ["Median duration", "21 s"]], filters: "status, source (composer / remix / AI change), customer, project, created range, stuck > 15 min, quality, size", actions: "View (A06), Reconcile now", sort: "created desc", badges: [n(2)] })}</div>
  ${rt("Studio › Projects", ["Project", "Customer", "Takes ", "Spend ", "Updated"], [["Lighthouse series", "Ana López", "8", "$0.3525", "1 min ago"], ["Untitled project", "Ana López", "0", "$0.0000", "1 d ago"]], { filters: "customer, has takes in progress", actions: "View (takes relation manager)", sort: "updated desc" })}
  ${rt("Studio › AI suggestions", ["Customer", "Base take", "Instruction", "Status", "Tokens in/out ", "Charged ", "Result"], [["Ana López", "#8", "warmer light, golden…", badge("succeeded").replace("Done", "applied"), "1,500 / 200", "$0.0003125", "#10"], ["Demo customer", "#3", "remove the boat", badge("queued").replace("Queued", "discarded"), "1,500 / 200", "$0.0003125", "−"]], { filters: "status (proposed, applied, discarded, failed), customer, range", actions: "View (instruction, full proposal JSON, rationale, usage record)", sort: "created desc", badges: [n(3)] })}
</div>`, [
  "Outstanding credits = sum of all customer balances: what the business owes in prepaid usage. A real billing system watches this number.",
  "The takes table is the operational heart: <i>Stuck &gt; 15 min</i> is a saved filter used by the dashboard's Needs attention widget.",
  "AI suggestions are auditable like takes: tokens, exact charge and whether the proposal was applied.",
]);

add("a10-tables-billing", "A10 · Tables: Billing cluster", "Ledger, purchases and invoices. Everything read-only except the audited credit adjustment (A05).", `
<div class="screen" style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px;background:#f2f2f2;align-content:start;height:auto;min-height:900px">
  <div style="grid-column:1/3">${rt("Billing › Ledger entries", ["When (UTC)", "Customer", "Type", "Provider", "Reference", "Amount (nanodollars) ", "Amount "], [["Nov 17 12:01:31", "Ana López", "<span class='badge b-s'>charge</span>", "pixelforge", "take #10", "−74,037,500", "−$0.0740375"], ["Nov 17 11:58:02", "Ana López", "<span class='badge b-s'>charge</span>", "anthropic", "suggestion on #8", "−312,500", "−$0.0003125"], ["Nov 17 10:02:11", "Ana López", "<span class='badge b-s'>purchase</span>", "−", "INV-2026-000004", "+10,000,000,000", "+$10.00"], ["Nov 16 18:52:40", "Ana López", "<span class='badge b-s'>charge</span>", "pixelforge", "take #8", "−107,725,000", "−$0.107725"], ["Nov 16 18:20:05", "Ana López", "<span class='badge b-q'>promo_grant</span>", "−", "welcome credit", "+1,000,000,000", "+$1.00"]], { widgets: [["Purchases (range)", "$1,240.00"], ["Charges (range)", "$8.886875"], ["Open holds", "$0.3140"], ["Invariant: Σ ledger = Σ balances", "✓ holds"]], filters: "type (purchase, promo_grant, charge, adjustment), provider, customer, range", actions: "Open reference (take, suggestion, invoice)", sort: "id desc (append-only)", badges: [n(1)] })}</div>
  ${rt("Billing › Purchases", ["Reference", "Customer", "Amount ", "Invoice", "Paid at"], [["SIM-01JD8X…", "Ana López", "$10.00", "INV-2026-000004", "Nov 17 10:02"], ["SIM-01JD7Q…", "Demo customer", "$25.00", "INV-2026-000003", "Nov 16 19:40"]], { filters: "customer, range, package ($5/$10/$25)", actions: "View, Open invoice", sort: "paid at desc", badges: [n(2)] })}
  ${rt("Billing › Invoices", ["Number", "Customer", "Total ", "Status", "Issued"], [["INV-2026-000004", "Ana López", "$10.00", "<span class='badge b-s'>paid</span>", "Nov 17"], ["INV-2026-000003", "Demo customer", "$25.00", "<span class='badge b-s'>paid</span>", "Nov 16"]], { filters: "customer, year, range", actions: "View, Print (same printable view as P09)", sort: "number desc", badges: [n(3)] })}
</div>`, [
  "Append-only, money only (holds are open usage records, D-024). No edit or delete actions; the model blocks updates. The invariant widget recomputes Σ(ledger) per customer vs the cached balance column and Σ(open holds) vs held.",
  "Purchases are simulated payments; each one has exactly one invoice (unique FK).",
  "Gapless numbering per year (INV-YYYY-NNNNNN) from a locked counter row; voiding is out of scope.",
]);

add("a11-tables-providers", "A11 · Tables: Providers cluster", "Provider-facing evidence: webhook deliveries, AI usage, pricing drift. Users, Roles and Activity log are Filament/Shield defaults.", `
<div class="screen" style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px;background:#f2f2f2;align-content:start;height:auto;min-height:900px">
  <div style="grid-column:1/3">${rt("Providers › Webhook deliveries (D-027)", ["Received", "Event id", "Type", "Take", "Attempt ", "Outcome", "Payload"], [["12:01:31", "evt_b7…", "generation.succeeded", "#10", "1", "<span class='badge b-s'>applied</span>", "view JSON"], ["12:01:32", "evt_b7…", "generation.succeeded", "#10", "2", "<span class='badge b-q'>duplicate</span>", "view JSON"], ["12:02:10", "evt_c9…", "generation.processing", "#10", "1", "<span class='badge b-p'>ignored_stale</span>", "view JSON"], ["11:00:00", "evt_p1…", "ping", "−", "1", "<span class='badge b-s'>ping</span>", "view JSON"]], { widgets: [["Deliveries (24 h)", "318"], ["Duplicates", "22"], ["Ignored stale", "4"], ["Unknown take / orphan success", "0 / 0"]], filters: "type, outcome, range, take", actions: "View payload", sort: "received desc", badges: [n(1)] })}</div>
  ${rt("Providers › Anthropic usage (page)", ["Day (UTC)", "Model", "Calls ", "Input tok ", "Output tok ", "Cost ", "Charged "], [["Nov 17", "claude-haiku-5-5", "9", "13,500", "1,800", "$0.002250", "$0.0028125"], ["Nov 16", "claude-haiku-5-5", "15", "22,500", "3,000", "$0.003750", "$0.0046875"]], { filters: "range, model", actions: "−", badges: [n(2)] })}
  ${rt("Providers › Pricing (page)", ["Kind", "Live /v1/pricing", "Used for estimates", "Status"], [["input_text_tokens", "$5.00 / 1M", "$5.00 / 1M", "<span class='badge b-s'>same</span>"], ["output_tokens", "$40.00 / 1M", "$40.00 / 1M", "<span class='badge b-s'>same</span>"]], { filters: "−", actions: "Refresh cache", badges: [n(3)] })}
</div>`, [
  "<code>webhook_events</code> keeps one row per event (recorded once); <code>webhook_deliveries</code> logs every verified attempt with its outcome (D-027). Rejected signatures are only logged.",
  "Our own record of Anthropic usage per call (tokens × rates snapshot). The Anthropic console is the external cross-check.",
  "Shows drift between live pricing and the rates our estimator uses; actual charges always use the per-take rates snapshot.",
]);

// ---------- render ----------
const page = (s) => `<!doctype html><html lang="en"><meta charset="utf-8"><style>${CSS}</style><body><div class="sheet" id="sheet"><h1>${s.title}</h1><p class="sub">${s.sub}</p><div class="layout">${s.screen}<div class="notes"><h3>Decisions</h3><ol>${s.notes.map((t, i) => `<li><i class="n">${i + 1}</i><span>${t}</span></li>`).join("")}</ol></div></div></div></body></html>`;

fs.mkdirSync(path.join(here, "html"), { recursive: true });
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 2600, height: 1200 }, deviceScaleFactor: 1 });
const pg = await ctx.newPage();
for (const s of sheets) {
  const f = path.join(here, "html", `${s.file}.html`);
  fs.writeFileSync(f, page(s));
  await pg.goto("file://" + f);
  await pg.locator("#sheet").screenshot({ path: path.join(out, `${s.file}.png`) });
  console.log("rendered", s.file);
}
await browser.close();
const idx = `<!doctype html><html lang="en"><meta charset="utf-8"><title>Takes wireframes</title><style>body{font-family:Helvetica,Arial,sans-serif;background:#e9e9e9;margin:0;padding:30px}h1{font-size:32px}h2{font-size:24px;margin:40px 0 10px}img{max-width:100%;border:2px solid #888;background:#fff}</style><body><h1>Takes · wireframes (public app P01–P10, admin console A02–A07 (login/MFA/resources are Filament built-ins))</h1>${sheets.map((s) => `<h2>${s.title}</h2><img src="${s.file}.png" alt="${s.title}">`).join("")}</body></html>`;
fs.writeFileSync(path.join(out, "index.html"), idx);
console.log("done:", sheets.length, "sheets");
