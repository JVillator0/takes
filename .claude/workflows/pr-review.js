export const meta = {
  name: 'pr-review',
  description: 'Review a PR with up to three specialist reviewers and a judge; label the verdict and auto-merge when MERGEABLE (spec 1-03)',
  whenToUse: 'Run on an open PR whose CI has finished. args: { pr, repo, cwd, round?, judgeOnly?, escalation? ("D-075", round 4 only) }',
  phases: [
    { title: 'Context', detail: 'PR metadata, head SHA, CI, linked issue, files classified into reviewer scopes' },
    { title: 'Review', detail: 'one GitHub review per reviewer with matching files (max 3 in parallel)' },
    { title: 'Judge', detail: 'scorecard, gates, verdict label; merge only on MERGEABLE at the reviewed SHA' },
  ],
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

const input = args || {}
const PR = Number(input.pr)
const REPO = input.repo || 'JVillator0/takes'
const CWD = input.cwd
if (!Number.isInteger(PR) || PR <= 0) throw new Error('args.pr (PR number) is required')
if (!CWD) throw new Error('args.cwd (absolute path of the checkout that holds .claude/) is required')
if (!/^[\w.-]+\/[\w.-]+$/.test(REPO)) throw new Error('args.repo must look like "owner/name"')

const MAX_ROUNDS = 3
const MODEL_LABEL = 'Claude Opus 5.5'
const VERDICT_LABELS = {
  MERGEABLE: 'verdict:mergeable',
  'MERGEABLE AFTER NITS': 'verdict:nits',
  'NOT MERGEABLE': 'verdict:not-mergeable',
}

// ---------------------------------------------------------------------------
// Reviewer scopes (spec 1-02). The checklist is only the fallback used when the
// agent definition file does not exist in args.cwd.
// ---------------------------------------------------------------------------

const isFilamentPath = (p) =>
  p.startsWith('app/Filament/') || p.startsWith('app/Providers/Filament/') || /^lang\/[^/]+\/console\.php$/.test(p)

const REVIEWERS = [
  {
    id: 'laravel-backend-reviewer',
    name: 'Laravel Backend Review',
    matches: (p) =>
      !isFilamentPath(p) &&
      (p.startsWith('app/') || p.startsWith('database/') || p.startsWith('routes/') || p.startsWith('config/') || p.startsWith('tests/')),
    fallback: [
      'Scope: app/ except app/Filament, database/, routes/, config/, tests/.',
      'Architecture rules in .claude/rules/architecture.md (Actions final readonly with execute(), Queries, DTOs, backed Enums, no DB/query builder in controllers).',
      'Every item of .claude/rules/money.md and .claude/rules/webhooks.md: integer nanodollars only (no float, round(), number_format() in money paths); lock order take|suggestion -> customer; settlement only inside a terminal transition, exactly once; take transitions only in ApplyTakeSnapshotAction; no HTTP call inside a DB transaction; no queue delay above 900 s; holds are state, not ledger rows; webhook signature on the raw body, event_id recorded once.',
      'Idempotency, locks and ordering; auth:customer guard on customer routes; migrations reversible with real constraints; Larastan level 8 friendly types; strict_types.',
      'Tests: meaningful, cover failure paths, would fail if the change were reverted (no placebo tests).',
    ],
  },
  {
    id: 'inertia-vue-reviewer',
    name: 'Inertia Vue Review',
    matches: (p) => p.startsWith('resources/js/') || p.startsWith('resources/css/') || /^vite\.config\./.test(p),
    fallback: [
      'Scope: resources/js/, resources/css/, vite.config.*.',
      'Composition API with <script setup lang="ts">, strict TypeScript, no any; shared types in resources/js/types/; Wayfinder for routes.',
      'Inertia v3 patterns: forms, partial reloads, polling; Echo listeners subscribed and cleaned up with the component lifecycle.',
      'Money arrives as server-formatted strings, never JS numbers; copy matches the 2-01 copy tables and lang/en/*.php (state, failure, denial, billing, AI strings).',
      'Accessibility: labels, focus order, keyboard reachability, contrast, aria-live for status changes.',
    ],
  },
  {
    id: 'filament-reviewer',
    name: 'Filament Review',
    matches: isFilamentPath,
    fallback: [
      'Scope: app/Filament/, app/Providers/Filament/, lang/*/console.php.',
      'Filament 5 APIs and namespaces; clusters and the navigation builder; Tables/Schemas/Actions/Pages split per .claude/rules/filament.md.',
      'Shield permissions on every resource, page and action; write actions are audited.',
      'Query efficiency: withCount/withSum, no N+1; no widget polling faster than 30 s and never on pages with heavy aggregates.',
      'filacheck rules.',
    ],
  },
]

const DOC_PATTERNS = [/\.md$/i, /^docs\//, /^\.claude\/specs\//, /^LICENSE/i, /\.(png|jpe?g|gif|svg|webp)$/i]
const GENERATED_PATTERNS = [
  /(^|\/)composer\.lock$/,
  /(^|\/)package-lock\.json$/,
  /(^|\/)pnpm-lock\.yaml$/,
  /^resources\/js\/(actions|routes|wayfinder)\//,
  /^public\/build\//,
  /^bootstrap\/ssr\//,
  /^_ide_helper.*\.php$/,
  /^\.phpstorm\.meta\.php$/,
]
const MONEY_OR_WEBHOOK = /(money|nanodollar|billing|ledger|usage.?record|settle|charge|purchase|invoice|credit|webhook|pixelforge|suggestion)/i

const isDoc = (p) => DOC_PATTERNS.some((r) => r.test(p))
const isGenerated = (p) => GENERATED_PATTERNS.some((r) => r.test(p))

// ---------------------------------------------------------------------------
// Schemas
// ---------------------------------------------------------------------------

const CONTEXT_SCHEMA = {
  type: 'object',
  properties: {
    state: { type: 'string', description: 'OPEN, CLOSED or MERGED' },
    isDraft: { type: 'boolean' },
    url: { type: 'string' },
    title: { type: 'string' },
    body: { type: 'string', description: 'PR body, verbatim' },
    headSha: { type: 'string', description: 'full 40-char head commit SHA (headRefOid)' },
    baseSha: { type: 'string', description: 'full 40-char SHA of the base branch tip (baseRefOid)' },
    objectsFetched: { type: 'boolean', description: 'true when git cat-file -e succeeded for both headSha and baseSha in args.cwd' },
    headRef: { type: 'string' },
    baseRef: { type: 'string' },
    files: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          path: { type: 'string' },
          status: { type: 'string' },
          additions: { type: 'integer' },
          deletions: { type: 'integer' },
        },
        required: ['path', 'status', 'additions', 'deletions'],
      },
    },
    linkedIssue: {
      type: ['object', 'null'],
      properties: {
        number: { type: 'integer' },
        title: { type: 'string' },
        body: { type: 'string' },
        labels: { type: 'array', items: { type: 'string' } },
      },
      required: ['number', 'title', 'body', 'labels'],
    },
    specRef: { type: ['string', 'null'], description: 'spec id such as "4" or "3-03", from the PR body, issue or labels' },
    specPath: { type: ['string', 'null'], description: 'path of the spec file under .claude/specs/ in args.cwd, if found' },
    ci: {
      type: 'object',
      properties: {
        checks: {
          type: 'array',
          items: {
            type: 'object',
            properties: { name: { type: 'string' }, bucket: { type: 'string' }, link: { type: 'string' } },
            required: ['name', 'bucket'],
          },
        },
      },
      required: ['checks'],
    },
    labels: { type: 'array', items: { type: 'string' } },
    previousJudgeRounds: { type: 'integer', description: 'number of earlier judge scorecard comments on this PR' },
  },
  required: [
    'state', 'isDraft', 'url', 'title', 'body', 'headSha', 'baseSha', 'objectsFetched', 'headRef', 'baseRef', 'files',
    'linkedIssue', 'specRef', 'specPath', 'ci', 'labels', 'previousJudgeRounds',
  ],
}

const FINDING = {
  type: 'object',
  properties: {
    path: { type: 'string' },
    line: { type: ['integer', 'null'], description: 'line in the new file (RIGHT side); null for a file-level finding' },
    severity: { type: 'string', enum: ['critical', 'important', 'minor'] },
    title: { type: 'string' },
    body: { type: 'string' },
    suggestion: { type: 'string' },
  },
  required: ['path', 'line', 'severity', 'title', 'body'],
}

// Matches the reviewer agents' output contract ({reviewer, summary, findings});
// the extra fields are optional so a definition that only returns those three still validates.
const REVIEW_SCHEMA = {
  type: 'object',
  properties: {
    reviewer: { type: 'string' },
    summary: { type: 'string' },
    findings: { type: 'array', items: FINDING },
    checked: { type: 'array', items: { type: 'string' }, description: 'what you verified, file by file' },
    definitionSource: { type: 'string', enum: ['agent-file', 'inline-fallback'] },
  },
  required: ['summary', 'findings'],
}

const POST_REVIEW_SCHEMA = {
  type: 'object',
  properties: {
    posted: { type: 'boolean' },
    alreadyPosted: { type: 'boolean' },
    reviewUrl: { type: ['string', 'null'] },
    inlineComments: { type: 'integer' },
    movedToBody: { type: 'integer', description: 'inline comments moved into the body after a 422' },
    error: { type: ['string', 'null'] },
  },
  required: ['posted', 'alreadyPosted', 'reviewUrl', 'inlineComments', 'movedToBody', 'error'],
}

const DIMENSIONS = [
  'Intent & scope',
  'Correctness',
  'Money safety',
  'Robustness',
  'Architecture & conventions',
  'Clarity',
  'Tests',
  'Security & authorization',
  'UX vs wireframes',
]

const GATE = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['pass', 'fail', 'pending', 'na'] },
    note: { type: 'string' },
  },
  required: ['status', 'note'],
}

const GATE_KEYS = ['ci', 'ccTitle', 'noFloat', 'reversibleMigrations', 'secrets', 'prBody', 'explainEveryLine', 'english']
const GATE_LABELS = {
  ci: 'CI',
  ccTitle: 'CC title',
  noFloat: 'no float',
  reversibleMigrations: 'reversible migrations',
  secrets: 'secrets',
  prBody: 'PR body',
  explainEveryLine: 'explain every line',
  english: 'English',
}

const JUDGE_SCHEMA = {
  type: 'object',
  properties: {
    scores: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          dimension: { type: 'string', enum: DIMENSIONS },
          score: { type: ['integer', 'null'], minimum: 0, maximum: 10, description: 'null = N/A' },
          note: { type: 'string' },
        },
        required: ['dimension', 'score', 'note'],
      },
    },
    gates: {
      type: 'object',
      properties: Object.fromEntries(GATE_KEYS.map((k) => [k, GATE])),
      required: GATE_KEYS,
    },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          severity: { type: 'string', enum: ['critical', 'important', 'minor'] },
          origin: { type: 'string', enum: ['introduced', 'pre-existing'] },
          status: { type: 'string', enum: ['open', 'resolved', 'accepted', 'dismissed'] },
          path: { type: 'string' },
          line: { type: ['integer', 'null'] },
          title: { type: 'string' },
          source: { type: 'string', description: 'reviewer id that raised it, or "pr-judge"' },
          note: { type: 'string', description: 'why dismissed/accepted/resolved, or what must change' },
        },
        required: ['severity', 'origin', 'status', 'path', 'line', 'title', 'source', 'note'],
      },
    },
    moneyOrWebhook: { type: 'boolean', description: 'true if the PR touches money or webhook paths' },
    verdict: { type: 'string', enum: Object.keys(VERDICT_LABELS) },
    mustChange: { type: 'array', items: { type: 'string' } },
    checked: { type: 'array', items: { type: 'string' } },
    summary: { type: 'string' },
  },
  required: ['scores', 'gates', 'findings', 'moneyOrWebhook', 'verdict', 'mustChange', 'checked', 'summary'],
}

const PUBLISH_SCHEMA = {
  type: 'object',
  properties: {
    headShaAtDecision: { type: 'string' },
    ciAtDecision: { type: 'string', enum: ['pass', 'fail', 'pending', 'none'] },
    outcome: {
      type: 'string',
      enum: ['merged', 'auto-merge-enabled', 'not-merged-verdict', 'refused-sha-changed', 'refused-ci', 'refused-state', 'merge-error'],
    },
    decisionLine: { type: 'string', description: 'the exact "Decision: …" line that was posted' },
    commentUrl: { type: ['string', 'null'] },
    labelsAfter: { type: 'array', items: { type: 'string' } },
    error: { type: ['string', 'null'] },
  },
  required: ['headShaAtDecision', 'ciAtDecision', 'outcome', 'decisionLine', 'commentUrl', 'labelsAfter', 'error'],
}

// ---------------------------------------------------------------------------
// Helpers (pure)
// ---------------------------------------------------------------------------

const short = (sha) => (sha || '').slice(0, 7)
const json = (v) => JSON.stringify(v, null, 2)
const SEVERITY_ORDER = { critical: 0, important: 1, minor: 2 }
const ORIGIN_ORDER = { introduced: 0, 'pre-existing': 1 }

const isSha = (v) => typeof v === 'string' && /^[0-9a-f]{40}$/.test(v)

function deriveCiOverall(checks) {
  const buckets = (checks || []).map((c) => c.bucket)
  if (buckets.some((b) => b === 'fail' || b === 'cancel')) return 'fail'
  if (buckets.some((b) => b === 'pending')) return 'pending'
  if (buckets.some((b) => b === 'pass')) return 'pass'
  return 'none'
}

// One entry per dimension, and Money safety must be scored on a money/webhook PR.
function validateScores(judge, { moneyOrWebhook }) {
  const problems = []
  for (const d of DIMENSIONS) {
    const n = judge.scores.filter((s) => s.dimension === d).length
    if (n === 0) problems.push(`scorecard is missing "${d}"`)
    if (n > 1) problems.push(`scorecard has ${n} entries for "${d}"`)
  }
  const money = judge.scores.find((s) => s.dimension === 'Money safety')
  if (moneyOrWebhook && money && money.score === null) problems.push('Money safety is N/A on a PR that touches money or webhook paths')
  return problems
}

function verdictRank(v) {
  return { MERGEABLE: 0, 'MERGEABLE AFTER NITS': 1, 'NOT MERGEABLE': 2 }[v]
}

function hasExplainChecked(body) {
  return /-\s*\[[xX]\]\s*I can explain every line/i.test(body || '')
}

const TEMPLATE_SECTIONS = ['Summary', 'Why', 'Spec section', 'Changes', 'Money impact', 'Test evidence', 'Screenshots', 'Risks', 'Checklist']
function missingSections(body) {
  const headings = (body || '')
    .split('\n')
    .filter((l) => /^#{1,6}\s/.test(l))
    .map((l) => l.replace(/^#{1,6}\s+/, '').trim().toLowerCase())
  return TEMPLATE_SECTIONS.filter((s) => !headings.some((h) => h.startsWith(s.toLowerCase())))
}

// The model scores; this code enforces the thresholds of spec 1-03. The final
// verdict is the stricter of the judge's verdict and the computed one.
function computeVerdict(judge, { moneyOrWebhook = false } = {}) {
  const applicable = judge.scores.filter((s) => s.score !== null && s.score !== undefined)
  const avg = applicable.length ? applicable.reduce((a, s) => a + s.score, 0) / applicable.length : 0
  const score = Math.round(avg * 10) / 10
  const blocking = judge.findings.filter((f) => f.origin === 'introduced' && f.status === 'open')
  const criticals = blocking.filter((f) => f.severity === 'critical').length
  const importants = blocking.filter((f) => f.severity === 'important').length
  const reasons = validateScores(judge, { moneyOrWebhook })

  for (const dim of ['Money safety', 'Robustness']) {
    const s = judge.scores.find((x) => x.dimension === dim)
    if (s && s.score !== null && s.score < 7) reasons.push(`${dim} scored ${s.score} (< 7)`)
  }
  for (const k of GATE_KEYS) {
    const g = judge.gates[k]
    if (g.status === 'fail') reasons.push(`gate failed: ${GATE_LABELS[k]} (${g.note})`)
  }
  if (criticals) reasons.push(`${criticals} open critical finding(s)`)
  if (importants) reasons.push(`${importants} open important finding(s)`)

  let computed
  if (reasons.length || avg < 7) computed = 'NOT MERGEABLE'
  else if (avg >= 8) computed = 'MERGEABLE'
  else computed = 'MERGEABLE AFTER NITS'
  if (avg < 7) reasons.push(`average ${score} < 7.0`)

  const verdict = verdictRank(judge.verdict) > verdictRank(computed) ? judge.verdict : computed
  return { verdict, computed, score, reasons }
}

function numberFindings(findings) {
  return findings
    .slice()
    .sort(
      (a, b) =>
        SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity] ||
        ORIGIN_ORDER[a.origin] - ORIGIN_ORDER[b.origin] ||
        a.path.localeCompare(b.path) ||
        (a.line || 0) - (b.line || 0),
    )
    .map((f, i) => ({ n: i + 1, ...f }))
}

function gateMark(g) {
  return { pass: '✓', fail: '✗', pending: '⏳', na: 'n/a' }[g.status]
}

function renderScorecard({ verdict, score, judge, numbered, round, sha, reviewerLine, reasons, roundLimit = MAX_ROUNDS, escalation = null }) {
  const cell = (s) => String(s || '').replace(/\|/g, '\\|').replace(/\n/g, ' ')
  const rows = DIMENSIONS.map((d) => {
    const s = judge.scores.find((x) => x.dimension === d)
    return `| ${d} | ${s && s.score !== null ? s.score : 'N/A'} | ${cell(s ? s.note : 'not scored')} |`
  })
  const gates = GATE_KEYS.map((k) => `${GATE_LABELS[k]} ${gateMark(judge.gates[k])}`).join(' · ')
  const findingLines = numbered.length
    ? numbered.map((f) => {
        const where = f.line ? `${f.path}:${f.line}` : f.path
        const status = f.status === 'open' ? '' : ` · ${f.status}`
        return `- #${f.n} (${f.severity}, ${f.origin}${status}) \`${where}\` ${f.title}${f.note ? ` — ${cell(f.note)}` : ''} _[${f.source}]_`
      })
    : ['- none']
  const must = verdict === 'MERGEABLE' ? [] : [...reasons, ...judge.mustChange].filter((v, i, a) => a.indexOf(v) === i)
  return [
    `## Judge verdict: ${verdict} · ${score.toFixed(1)} / 10`,
    `<!-- pr-review:judge sha=${sha} round=${round} -->`,
    `Round ${round} of ${roundLimit}${escalation ? ' (' + escalation + ')' : ''} · reviewed head \`${short(sha)}\` · ${reviewerLine}`,
    '',
    '| Dimension | Score | Note |',
    '|---|---|---|',
    ...rows,
    '',
    `Gates: ${gates}`,
    '',
    'Findings:',
    ...findingLines,
    ...(must.length ? ['', 'Must change:', ...must.map((m) => `- ${m}`)] : []),
    '',
    judge.summary,
    '',
    `_pr-judge · automated judge (${MODEL_LABEL})_`,
  ].join('\n')
}

function renderReview({ name, marker, review, files }) {
  const counts = ['critical', 'important', 'minor']
    .map((sev) => `${review.findings.filter((f) => f.severity === sev).length} ${sev}`)
    .join(' · ')
  const inline = review.findings.filter((f) => Number.isInteger(f.line) && f.line > 0)
  const fileLevel = review.findings.filter((f) => !inline.includes(f))
  const commentBody = (f) =>
    [`**${f.severity}** · ${f.title}`, '', f.body, ...(f.suggestion ? ['', `**Suggestion:** ${f.suggestion}`] : [])].join('\n')
  const body = [
    `${name} · automated reviewer (${MODEL_LABEL})`,
    marker,
    '',
    review.summary,
    '',
    `Files: ${files.map((p) => `\`${p}\``).join(', ')}`,
    `Findings: ${counts}`,
    ...(fileLevel.length ? ['', 'File-level findings:', ...fileLevel.map((f) => `- **${f.severity}** \`${f.path}\` ${f.title}: ${f.body}`)] : []),
    ...(review.checked && review.checked.length ? ['', 'Checked:', ...review.checked.map((c) => `- ${c}`)] : []),
  ].join('\n')
  return {
    body,
    comments: inline.map((f) => ({ path: f.path, line: f.line, side: 'RIGHT', body: commentBody(f) })),
  }
}

// ---- end of pure helpers: .claude/tests/pr-review-workflow.test.mjs evaluates everything above this line ----

// ---------------------------------------------------------------------------
// Phase 1: Context
// ---------------------------------------------------------------------------

phase('Context')

const ctx = await agent(
  `Collect the facts the PR review workflow needs for PR #${PR} in ${REPO}. You only read; never comment, label, push or check out branches (the working tree in ${CWD} is shared with the lead session).

Run from ${CWD}:
1. \`gh pr view ${PR} -R ${REPO} --json number,state,isDraft,url,title,body,headRefOid,baseRefOid,headRefName,baseRefName,labels,closingIssuesReferences\`. headSha = headRefOid, baseSha = baseRefOid (full 40-char SHAs).
2. Changed files with pagination: \`gh api --paginate repos/${REPO}/pulls/${PR}/files --jq '.[] | {path: .filename, status, additions, deletions}'\`. Return every file.
3. CI: \`gh pr checks ${PR} -R ${REPO} --json name,bucket,link\`. If any check has bucket "pending", wait once with \`gh pr checks ${PR} -R ${REPO} --watch --interval 30\` (Bash timeout 560000 ms), then read the JSON again. Return every check with its bucket exactly as gh reports it.
4. Linked issue: the first of closingIssuesReferences, or else a "Closes|Fixes|Resolves #N" in the body. Read it with \`gh issue view N -R ${REPO} --json number,title,body,labels\`. null when there is none.
5. Spec reference: look in the PR body ("Spec section", "Refs spec …"), the issue body and \`spec/<n>\` labels. If found, locate the matching spec file under ${CWD}/.claude/specs/ (e.g. "3-03" -> 3-*/03-SPEC.md, "4" -> 4-*/SPEC.md or the folder's main file) and return its path relative to ${CWD}; otherwise null.
6. Fetch the commits once for every later agent (they must not fetch in parallel): \`git -C ${CWD} fetch --quiet origin pull/${PR}/head <baseSha>\`, then \`git -C ${CWD} cat-file -e <headSha>^{commit} && git -C ${CWD} cat-file -e <baseSha>^{commit}\`. objectsFetched = true only if both succeed.
7. previousJudgeRounds: count the PR's issue comments whose body contains "<!-- pr-review:judge " (\`gh api --paginate repos/${REPO}/issues/${PR}/comments --jq '.[].body'\`).

Return the body and titles verbatim (do not summarise them).`,
  { label: 'context', phase: 'Context', schema: CONTEXT_SCHEMA },
)
if (!ctx) throw new Error('Context agent returned nothing; nothing was posted.')

if (ctx.state !== 'OPEN') {
  log(`PR #${PR} is ${ctx.state}; nothing to review.`)
  return { verdict: null, score: null, merged: ctx.state === 'MERGED', findings: [], reason: `PR is ${ctx.state}` }
}

const round = Number.isInteger(input.round) ? input.round : ctx.previousJudgeRounds + 1
// D-075: after round MAX_ROUNDS the lead may authorise exactly one extra round by passing
// args.escalation (the decision id, e.g. "D-075"); anything beyond that is refused.
const ESCALATION_DECISION = 'D-075'
const escalation = input.escalation === ESCALATION_DECISION && round === MAX_ROUNDS + 1 ? ESCALATION_DECISION : null
const roundLimit = escalation ? MAX_ROUNDS + 1 : MAX_ROUNDS
if (round < 1 || round > roundLimit) {
  log(`Round ${round} exceeds the ${roundLimit}-round limit: escalate instead of re-running.`)
  return { verdict: null, score: null, merged: false, findings: [], round, reason: 'round limit reached; the lead decides (D-075: one extra round with escalation "D-075")' }
}

if (!isSha(ctx.headSha) || !isSha(ctx.baseSha)) {
  throw new Error(`Context returned an invalid SHA (head "${ctx.headSha}", base "${ctx.baseSha}"); nothing was posted.`)
}
if (!/^[\w./-]+$/.test(ctx.baseRef)) throw new Error(`Context returned an invalid base ref "${ctx.baseRef}"; nothing was posted.`)
if (!ctx.objectsFetched) throw new Error(`Could not fetch ${short(ctx.headSha)} and ${short(ctx.baseSha)} into ${CWD}; nothing was posted.`)

const SHA = ctx.headSha
const BASE_SHA = ctx.baseSha
const CI = deriveCiOverall(ctx.ci.checks)
const paths = ctx.files.map((f) => f.path)
const reviewable = paths.filter((p) => !isDoc(p) && !isGenerated(p))
const docsOrGeneratedOnly = reviewable.length === 0
const scoped = REVIEWERS.map((r) => ({ ...r, files: reviewable.filter(r.matches) }))
const unscoped = reviewable.filter((p) => !REVIEWERS.some((r) => r.matches(p)))
const moneyHint = paths.some((p) => MONEY_OR_WEBHOOK.test(p))
const explainChecked = hasExplainChecked(ctx.body)
const missing = missingSections(ctx.body)

let judgeOnly = input.judgeOnly === true || docsOrGeneratedOnly
if (input.judgeOnly === true && ctx.labels.includes(VERDICT_LABELS['NOT MERGEABLE'])) {
  log('judgeOnly ignored: the last verdict was NOT MERGEABLE, which needs the full workflow (spec 1-03).')
  judgeOnly = docsOrGeneratedOnly
}

log(
  `PR #${PR} "${ctx.title}" · head ${short(SHA)} · round ${round} · CI ${CI} · ` +
    `${paths.length} files (${reviewable.length} reviewable, ${unscoped.length} unscoped)` +
    (judgeOnly ? ' · judge only' : ''),
)
if (ctx.isDraft) log('PR is a draft: it will be reviewed but never merged.')
if (CI === 'fail') log('CI is red: reviews still run, the CI gate will fail the verdict.')

// ---------------------------------------------------------------------------
// Phase 2: Review
// ---------------------------------------------------------------------------

phase('Review')

const active = judgeOnly ? [] : scoped.filter((r) => r.files.length > 0)
const notApplicable = scoped.filter((r) => !active.includes(r))
for (const r of notApplicable) log(`${r.name}: N/A (${judgeOnly ? 'judge-only run' : 'no files in scope'})`)

function reviewerPrompt(r) {
  return `You are the **${r.id}** for PR #${PR} in ${REPO} (head ${SHA}, base ${ctx.baseRef} at ${BASE_SHA}, round ${round}).

## Your definition
Read ${CWD}/.claude/agents/${r.id}.md and act as that agent: its scope, checklist, posture, severity rules and output contract apply. If the file does not exist, use this fallback checklist (and set definitionSource "inline-fallback"):
${r.fallback.map((l) => `- ${l}`).join('\n')}

## Your files (review ONLY these; other reviewers own the rest)
${r.files.map((p) => `- ${p}`).join('\n')}

## How to read the change (read-only; never check out branches: ${CWD} is shared)
- Both commits are already fetched into ${CWD}; do not run git fetch.
- Your diff, limited to your paths: \`git -C ${CWD} diff ${BASE_SHA}...${SHA} -- ${r.files.map((p) => `'${p.replace(/'/g, "'\\''")}'`).join(' ')}\`
- Full files at the reviewed head: \`git -C ${CWD} show ${SHA}:<path>\`. Read callers and related files the same way when you need them.
- PR intent: title "${ctx.title.replace(/"/g, '\\"')}"${ctx.linkedIssue ? `, issue #${ctx.linkedIssue.number}` : ''}${ctx.specPath ? `, spec ${ctx.specPath}` : ''}. Read the project rules your definition references (.claude/rules/*.md) from ${CWD}.

## Findings
- Each finding: path, line (line number in the NEW file, on a line that appears in your diff; null if file-level), severity critical|important|minor, title (one line), body (what is wrong, why it matters, evidence), optional suggestion (the fix).
- Be a gate, not a cheerleader: trace control flow to the real effect, distrust the PR description, and only report what you can point to at path:line. List in "checked" what you verified.
- English only. Do not post anything to GitHub: the workflow posts your review.
- Return reviewer "${r.id}", summary (one or two sentences), findings, checked.`
}

function postReviewPrompt(r, payload, marker) {
  return `Post one GitHub review on PR #${PR} in ${REPO} for the ${r.id}. Do not change its text. Run gh with \`-R ${REPO}\` or full API paths from a fresh \`mktemp -d\` directory.

1. Idempotency: if \`gh api --paginate repos/${REPO}/pulls/${PR}/reviews --jq '.[].body'\` already contains \`${marker}\`, post nothing; return posted=true, alreadyPosted=true and that review's html_url.
2. Write the JSON payload between the BEGIN/END lines to a file and run \`gh api --method POST repos/${REPO}/pulls/${PR}/reviews --input <file>\`.
3. If GitHub answers 422 because a comment line is not part of the diff, find which comments are outside the diff hunks (\`git -C ${CWD} diff ${BASE_SHA}...${SHA} -- <path>\`; a comment is valid only on a "+" or context line of the new file), move those into the body under "Findings outside the diff:" as "- <path>:<line> <comment body first line>", and post again. If it still fails, move every comment into the body and post once more. Never post more than one review; never use APPROVE or REQUEST_CHANGES.
4. Return posted, alreadyPosted, the review html_url, the number of inline comments that were posted, movedToBody, and error (null on success).

-----BEGIN PAYLOAD-----
${json(payload)}
-----END PAYLOAD-----`
}

async function reviewStage(r) {
  const review = await agent(reviewerPrompt(r), { label: r.id, phase: 'Review', schema: REVIEW_SCHEMA })
  if (!review) return { reviewer: r.id, name: r.name, files: r.files, failed: true, findings: [] }
  return { ...review, reviewer: r.id, name: r.name, files: r.files }
}

async function postStage(res, r) {
  if (res.failed) return res
  const marker = `<!-- pr-review:reviewer=${r.id} sha=${SHA} round=${round} -->`
  const { body, comments } = renderReview({ name: r.name, marker, review: res, files: r.files })
  const payload = { commit_id: SHA, event: 'COMMENT', body, comments }
  const post = await agent(postReviewPrompt(r, payload, marker), { label: `post ${r.id}`, phase: 'Review', schema: POST_REVIEW_SCHEMA })
  return { ...res, posted: !!post && post.posted, reviewUrl: post ? post.reviewUrl : null, postError: post ? post.error : 'post agent failed' }
}

// pipeline() turns a stage that throws into null; map results back by index so a
// crashed reviewer is reported as failed (and judged directly), never as N/A.
const reviewResults = active.length ? await pipeline(active, reviewStage, postStage) : []
const reviews = active.map((r, i) => {
  const res = reviewResults[i]
  const out = res || { reviewer: r.id, name: r.name, files: r.files, failed: true, findings: [] }
  if (out.failed) log(`${r.name}: agent failed; the judge reviews its files instead.`)
  else log(`${r.name}: ${out.findings.length} finding(s)${out.posted ? '' : ` · review NOT posted (${out.postError})`}`)
  return out
})
const reviewerFindings = reviews.flatMap((r) => r.findings.map((f) => ({ ...f, source: r.reviewer })))

// ---------------------------------------------------------------------------
// Phase 3: Judge
// ---------------------------------------------------------------------------

phase('Judge')

const reviewerLine = REVIEWERS.map((r) => {
  const done = reviews.find((x) => x.reviewer === r.id)
  if (!done) return `${r.name} N/A`
  if (done.failed) return `${r.name} failed (judged directly)`
  return `${r.name} ${done.findings.length} finding(s)`
}).join(' · ')

const judgeFacts = {
  pr: PR,
  repo: REPO,
  url: ctx.url,
  round,
  maxRounds: roundLimit,
  escalation,
  judgeOnly,
  reviewedHeadSha: SHA,
  baseRef: ctx.baseRef,
  baseSha: BASE_SHA,
  title: ctx.title,
  body: ctx.body,
  bodyMissingSections: missing,
  explainEveryLineChecked: explainChecked,
  moneyOrWebhookByPath: moneyHint,
  linkedIssue: ctx.linkedIssue,
  specRef: ctx.specRef,
  specPath: ctx.specPath,
  ci: { overall: CI, checks: ctx.ci.checks },
  files: ctx.files,
  docsOrGeneratedOnly,
  unscopedFiles: unscoped,
  reviewers: REVIEWERS.map((r) => {
    const done = reviews.find((x) => x.reviewer === r.id)
    return done
      ? { id: r.id, status: done.failed ? 'failed' : 'ran', files: done.files, reviewUrl: done.reviewUrl || null, summary: done.summary || null, checked: done.checked || [] }
      : { id: r.id, status: 'n/a', files: scoped.find((s) => s.id === r.id).files }
  }),
  reviewerFindings,
}

const judgePrompt = `You are the **pr-judge** for PR #${PR} in ${REPO}. Read ${CWD}/.claude/agents/pr-judge.md and act as that agent; its rubric, anchors, gates and finding rules apply. In this workflow you DO NOT post, label or merge: return the structured scorecard and the workflow renders and publishes it.

## Facts gathered by the workflow
\`\`\`json
${json(judgeFacts)}
\`\`\`

## What to read (never check out branches: ${CWD} is shared)
- Whole diff: \`git -C ${CWD} diff ${BASE_SHA}...${SHA}\` (both commits are already fetched; do not run git fetch). Files at the reviewed head: \`git -C ${CWD} show ${SHA}:<path>\`.
- The linked issue and the spec section (${ctx.specPath ? `${CWD}/${ctx.specPath}` : 'no spec file was found: say so under Intent & scope'}), plus ${CWD}/.claude/specs/README.md principles and the .claude/rules/*.md files that apply.
- You are the only reviewer of: unscopedFiles${reviews.some((r) => r.failed) ? ', and the files of every reviewer with status "failed"' : ''}${judgeOnly ? ', and (judge-only run) the whole diff' : ''}. Add your own findings for them with source "pr-judge".
${
  round > 1
    ? `- Round ${round}: read the earlier judge comments (marker "<!-- pr-review:judge ") and the earlier automated reviews and their inline threads (\`gh api --paginate repos/${REPO}/pulls/${PR}/reviews\`, \`gh api --paginate repos/${REPO}/pulls/${PR}/comments\`). For every earlier finding decide resolved (the fix is in the code at ${short(SHA)}, with a test when behaviour changed), accepted (a reasoned "Won't fix: …" you agree with) or still open, and carry it into your findings list.${judgeOnly ? ' This is a judge-only re-check after NITS: verify the fixes and look for anything the new commits broke.' : ''}`
    : ''
}

## What to return
- scores: exactly one entry for each of the 9 dimensions; score null (N/A) only where the dimension does not apply (e.g. Money safety on a PR that does not touch money; UX vs wireframes when no UI changed). Notes cite path:line.
- gates: every gate with pass | fail | pending | na and a short evidence note. ci comes from the facts (pending is not fail). explainEveryLine is na unless the PR touches money or webhook code; then pass only if explainEveryLineChecked is true. prBody fails if bodyMissingSections is non-empty or there is no issue link (see the bootstrap exception in your definition).
- findings: deduplicate the reviewer findings, verify each against the code (dismiss false positives with a reason), tag origin introduced | pre-existing, add your own. Do not number them; the workflow numbers them #1…#n.
- verdict per the thresholds in your definition; the workflow recomputes it and keeps the stricter one.
- mustChange: concrete changes required for MERGEABLE (empty when MERGEABLE).
- summary: 2–4 sentences an interviewer could read.`

let judge = await agent(judgePrompt, { label: 'pr-judge', phase: 'Judge', schema: JUDGE_SCHEMA })
if (!judge) throw new Error('Judge agent returned nothing; reviews were posted but no verdict was published. Re-run with resume.')
let moneyOrWebhook = judge.moneyOrWebhook || moneyHint
const scoreProblems = validateScores(judge, { moneyOrWebhook })
if (scoreProblems.length) {
  log(`Judge scorecard invalid (${scoreProblems.join('; ')}); asking once more.`)
  const retry = await agent(
    `${judgePrompt}\n\n## Your previous scorecard was rejected\n${scoreProblems.map((p) => `- ${p}`).join('\n')}\nReturn the full scorecard again with these fixed.`,
    { label: 'pr-judge (retry)', phase: 'Judge', schema: JUDGE_SCHEMA },
  )
  if (retry) {
    judge = retry
    moneyOrWebhook = judge.moneyOrWebhook || moneyHint
  }
}
if (moneyOrWebhook && !explainChecked && judge.gates.explainEveryLine.status !== 'fail') {
  judge.gates.explainEveryLine = { status: 'fail', note: 'money/webhook PR without the checked "I can explain every line" item' }
}
if (CI === 'fail') judge.gates.ci = { status: 'fail', note: judge.gates.ci.note || 'CI is red' }
if (CI === 'none') judge.gates.ci = { status: 'fail', note: 'no CI checks reported for the head commit' }

const { verdict, computed, score, reasons } = computeVerdict(judge, { moneyOrWebhook })
if (verdict !== judge.verdict) log(`Verdict adjusted by the rules: judge said ${judge.verdict}, rules give ${computed}.`)
const numbered = numberFindings(judge.findings)
const scorecard = renderScorecard({ verdict, score, judge, numbered, round, sha: SHA, reviewerLine, reasons, roundLimit, escalation })
const label = VERDICT_LABELS[verdict]
const otherLabels = Object.values(VERDICT_LABELS).filter((l) => l !== label)
const lastRound = round >= roundLimit

// D-075 next steps once the round limit is reached: after round MAX_ROUNDS the lead fixes
// and runs the one extra round with escalation "D-075"; after that round, minors go to a
// follow-up issue and nothing is re-run. Single quotes only: the line is quoted in the prompt.
const roundLimitDecision = escalation
  ? {
      nits: `Decision: not merged. Round ${round} of ${roundLimit} (${escalation}) was the last round: the lead opens a follow-up issue for the open minor findings; no round ${round + 1}.`,
      notMergeable: `Decision: not merged. Round ${round} of ${roundLimit} (${escalation}) was the last round and findings above minor remain: the lead decides what happens to the PR and records it there; no round ${round + 1}.`,
    }
  : {
      nits: `Decision: not merged. Round ${round} of ${roundLimit} reached (${ESCALATION_DECISION}): the lead fixes the minor findings in new commits, records the decision on the PR, then runs round ${round + 1} with judgeOnly and escalation '${ESCALATION_DECISION}', the last round.`,
      notMergeable: `Decision: not merged. Round ${round} of ${roundLimit} reached (${ESCALATION_DECISION}): the lead fixes the open important and critical findings in new commits, records the decision on the PR, then runs round ${round + 1} as a full run with escalation '${ESCALATION_DECISION}', the last round.`,
    }
const decisions = {
  merged: 'Decision: merged with squash.',
  autoMerge: 'Decision: auto-merge enabled (squash); GitHub merges when the required checks pass.',
  nits: lastRound
    ? roundLimitDecision.nits
    : `Decision: not merged. Fix the minor findings in new commits, reply "Fixed in <sha>" or "Won't fix: …" on each thread, then re-run with judgeOnly (round ${round + 1}).`,
  notMergeable: lastRound
    ? roundLimitDecision.notMergeable
    : `Decision: not merged. Fix the items above in new commits (no force-push), reply on each thread, then re-run the full workflow (round ${round + 1}).`,
}

const publish = await agent(
  `Publish the judge verdict for PR #${PR} in ${REPO}. Execute these steps exactly; do not change the verdict or the comment text.

Facts: verdict **${verdict}**, label \`${label}\`, reviewed head SHA \`${SHA}\`, draft=${ctx.isDraft}.
Run every gh command with \`-R ${REPO}\` from a fresh empty directory (\`cd "$(mktemp -d)"\`) so gh never touches a local checkout or worktree.

1. Re-read the PR: \`gh pr view ${PR} -R ${REPO} --json headRefOid,state,isDraft\` and \`gh pr checks ${PR} -R ${REPO} --json name,bucket\` (ci = fail if any bucket fail/cancel, pending if any pending, pass if at least one check and all pass/skipping, none if no checks). Record headShaAtDecision and ciAtDecision.
2. Choose the Decision line:
${
  verdict === 'MERGEABLE'
    ? `   - state is not OPEN or isDraft is true -> outcome "refused-state", Decision: "Decision: not merged, the PR is <state/draft>."
   - headRefOid != ${SHA} -> outcome "refused-sha-changed", Decision: "Decision: not merged. Head moved from ${short(SHA)} to <new short sha> after review; re-run the workflow."
   - ci is pending -> wait once with \`gh pr checks ${PR} -R ${REPO} --watch --interval 30\` (Bash timeout 560000 ms) and re-read; if still pending, outcome "refused-ci", Decision: "Decision: not merged, CI still pending; re-run the judge when it finishes."
   - ci is fail or none -> outcome "refused-ci", Decision: "Decision: not merged, CI is not green at merge time (<failing check names, or 'no checks'>)."
   - otherwise (open, not draft, head = ${SHA}, every check passed) merge:
     a. \`gh pr merge ${PR} -R ${REPO} --squash --auto --delete-branch --match-head-commit ${SHA}\`.
     b. If (a) fails with any error from enablePullRequestAutoMerge (GitHub says e.g. "clean status", "Protected branch rules not configured for this branch" or "Auto merge is not allowed for this repository": auto-merge has nothing to wait for or is unavailable), merge directly (the checks were already confirmed green above): \`gh pr merge ${PR} -R ${REPO} --squash --delete-branch --match-head-commit ${SHA}\`.
     c. Whatever (a) or (b) printed or exited with (e.g. "could not determine current branch" is printed AFTER a successful merge when gh cannot delete a local branch), re-read \`gh pr view ${PR} -R ${REPO} --json state,autoMergeRequest,mergeCommit\` and decide from it: state MERGED -> outcome "merged", Decision: "${decisions.merged}"; autoMergeRequest set -> outcome "auto-merge-enabled", Decision: "${decisions.autoMerge}"; otherwise -> outcome "merge-error", Decision: "Decision: not merged, gh pr merge failed: <one-line error>." and put the error in "error".`
    : `   - Do NOT merge. outcome "not-merged-verdict", Decision: "${verdict === 'MERGEABLE AFTER NITS' ? decisions.nits : decisions.notMergeable}"`
}
3. Post the scorecard as ONE issue comment: write the text between the BEGIN/END lines below to a file, append a blank line and the Decision line, and run \`gh pr comment ${PR} -R ${REPO} --body-file <file>\`. If a comment containing \`<!-- pr-review:judge sha=${SHA} round=${round} -->\` already exists, edit it instead (\`gh api --method PATCH repos/${REPO}/issues/comments/<id> -F body=@<file>\`).
4. Labels (\`gh pr edit ${PR} -R ${REPO} --add-label … --remove-label …\`; the verdict:* labels already exist, never create labels):
${
  verdict === 'MERGEABLE'
    ? `   - outcome "merged" or "auto-merge-enabled": add \`${label}\` and remove whichever of ${otherLabels.map((l) => `\`${l}\``).join(', ')} is present.
   - any other outcome (refused or merge error): add no label and remove every verdict:* label that is present; the PR needs a re-run.`
    : `   - add \`${label}\` and remove whichever of ${otherLabels.map((l) => `\`${l}\``).join(', ')} is present.`
}
5. Return what happened (labelsAfter = the PR's labels after step 4).

-----BEGIN SCORECARD-----
${scorecard}
-----END SCORECARD-----`,
  { label: 'publish verdict', phase: 'Judge', schema: PUBLISH_SCHEMA },
)

const merged = !!publish && publish.outcome === 'merged'
log(`${verdict} · ${score.toFixed(1)} / 10 · ${publish ? publish.decisionLine : 'publish step failed: nothing posted by the judge'}`)

return {
  verdict,
  score,
  merged,
  autoMergeEnabled: !!publish && publish.outcome === 'auto-merge-enabled',
  outcome: publish ? publish.outcome : 'publish-failed',
  decision: publish ? publish.decisionLine : null,
  commentUrl: publish ? publish.commentUrl : null,
  round,
  judgeOnly,
  reviewedSha: SHA,
  reviewers: Object.fromEntries(
    REVIEWERS.map((r) => {
      const done = reviews.find((x) => x.reviewer === r.id)
      return [r.id, done ? (done.failed ? 'failed' : done.reviewUrl || 'ran') : 'n/a']
    }),
  ),
  findings: numbered,
  verdictReasons: reasons,
}
