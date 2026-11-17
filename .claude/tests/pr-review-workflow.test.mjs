// Tests for .claude/workflows/pr-review.js. Run: node --test '.claude/tests/*.test.mjs'
//
// The workflow is a Workflow-tool script (top-level await and return, no imports),
// so it is evaluated here as the body of an async function with stubbed runtime
// hooks: agent(), parallel(), pipeline(), phase(), log() and args.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const SCRIPT = fileURLToPath(new URL('../workflows/pr-review.js', import.meta.url))
const source = readFileSync(SCRIPT, 'utf8')
const HELPERS_END = '// ---- end of pure helpers'
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const ARGS = { pr: 5, repo: 'JVillator0/takes', cwd: '/repo' }
const HEAD = 'a'.repeat(40)
const BASE = 'b'.repeat(40)
const DIMENSIONS = [
  'Intent & scope', 'Correctness', 'Money safety', 'Robustness', 'Architecture & conventions',
  'Clarity', 'Tests', 'Security & authorization', 'UX vs wireframes',
]

function body() {
  assert.ok(source.startsWith('export const meta = {'), 'script must start with export const meta')
  return source.replace(/^export const meta/, 'const meta')
}

async function loadHelpers() {
  const cut = body().indexOf(HELPERS_END)
  assert.ok(cut > 0, 'helpers marker missing from pr-review.js')
  const fn = new AsyncFunction(
    'args',
    body().slice(0, cut) +
      '\nreturn { computeVerdict, validateScores, missingSections, hasExplainChecked, numberFindings, deriveCiOverall, isSha, renderReview, meta }',
  )
  return fn(ARGS)
}

const h = await loadHelpers()

// ---------- fixtures ----------
const pass = { status: 'pass', note: 'ok' }
const gates = (o = {}) => ({
  ci: pass, ccTitle: pass, noFloat: pass, reversibleMigrations: pass, secrets: pass,
  prBody: pass, explainEveryLine: pass, english: pass, ...o,
})
const scores = (v, o = {}) =>
  DIMENSIONS.map((d) => ({ dimension: d, score: d in o ? o[d] : d === 'UX vs wireframes' ? null : v, note: 'n' }))
const finding = (o = {}) => ({
  severity: 'minor', origin: 'introduced', status: 'open', path: 'routes/web.php', line: 3,
  title: 't', source: 'laravel-backend-reviewer', note: '', ...o,
})
const judgeResult = (o = {}) => ({
  scores: scores(9), gates: gates(), findings: [], moneyOrWebhook: false, verdict: 'MERGEABLE',
  mustChange: [], checked: [], summary: 'sum', ...o,
})
const TEMPLATE_BODY = [
  'Summary', 'Why', 'Spec section', 'Changes', 'Money impact', 'Test evidence', 'Screenshots', 'Risks', 'Checklist',
].map((s) => `## ${s}`).join('\n') + '\n- [x] I can explain every line of this PR\nCloses #3'

const context = (files, o = {}) => ({
  state: 'OPEN', isDraft: false, url: 'u', title: 'feat(billing): x', body: TEMPLATE_BODY,
  headSha: HEAD, baseSha: BASE, objectsFetched: true, headRef: 'h', baseRef: 'main',
  files: files.map((p) => ({ path: p, status: 'modified', additions: 1, deletions: 0 })),
  linkedIssue: { number: 3, title: 't', body: 'b', labels: [] }, specRef: '6', specPath: null,
  ci: { checks: [{ name: 'ci', bucket: 'pass' }] }, labels: [], previousJudgeRounds: 0, ...o,
})

// ---------- full-run harness ----------
async function run({ args = ARGS, ctx, judge, judgeRetry, reviews = {}, throwFor = [], post }) {
  const calls = []
  const agent = async (prompt, opts) => {
    calls.push({ label: opts.label, prompt })
    if (throwFor.includes(opts.label)) throw new Error(`boom ${opts.label}`)
    if (opts.label === 'context') return ctx
    if (opts.label === 'pr-judge') return judge
    if (opts.label === 'pr-judge (retry)') return judgeRetry ?? judge
    if (opts.label.startsWith('post ')) {
      if (post !== undefined) return typeof post === 'function' ? post(opts.label) : post
      return { posted: true, alreadyPosted: false, reviewUrl: `r/${opts.label}`, inlineComments: 0, movedToBody: 0, error: null }
    }
    if (opts.label === 'publish verdict') {
      const mergeable = /verdict \*\*MERGEABLE\*\*/.test(prompt)
      return {
        headShaAtDecision: HEAD, ciAtDecision: 'pass', outcome: mergeable ? 'merged' : 'not-merged-verdict',
        decisionLine: 'Decision: x', commentUrl: 'c', labelsAfter: [], error: null,
      }
    }
    return { reviewer: opts.label, summary: 's', findings: reviews[opts.label] ?? [] }
  }
  const parallel = (thunks) => Promise.all(thunks.map((t) => t().catch(() => null)))
  const pipeline = (items, ...stages) =>
    Promise.all(
      items.map(async (item, i) => {
        try {
          let acc = item
          for (const stage of stages) acc = await stage(acc, item, i)
          return acc
        } catch {
          return null
        }
      }),
    )
  const logs = []
  const fn = new AsyncFunction('agent', 'parallel', 'pipeline', 'phase', 'log', 'args', 'budget', body())
  const result = await fn(agent, parallel, pipeline, () => {}, (m) => logs.push(m), args, { total: null })
  return { result, calls, logs, labels: calls.map((c) => c.label) }
}

// ---------- unit: helpers ----------
test('meta is a literal with the three phases', () => {
  assert.equal(h.meta.name, 'pr-review')
  assert.deepEqual(h.meta.phases.map((p) => p.title), ['Context', 'Review', 'Judge'])
})

test('deriveCiOverall', () => {
  assert.equal(h.deriveCiOverall([{ bucket: 'pass' }, { bucket: 'skipping' }]), 'pass')
  assert.equal(h.deriveCiOverall([{ bucket: 'pass' }, { bucket: 'pending' }]), 'pending')
  assert.equal(h.deriveCiOverall([{ bucket: 'pending' }, { bucket: 'cancel' }]), 'fail')
  assert.equal(h.deriveCiOverall([{ bucket: 'fail' }]), 'fail')
  assert.equal(h.deriveCiOverall([{ bucket: 'skipping' }]), 'none')
  assert.equal(h.deriveCiOverall([]), 'none')
})

test('isSha accepts only full lowercase 40-char SHAs', () => {
  assert.equal(h.isSha(HEAD), true)
  assert.equal(h.isSha('abc1234'), false)
  assert.equal(h.isSha('A'.repeat(40)), false)
  assert.equal(h.isSha(null), false)
})

test('hasExplainChecked needs the ticked checklist item', () => {
  assert.equal(h.hasExplainChecked('- [x] I can explain every line of this PR'), true)
  assert.equal(h.hasExplainChecked('- [X] I can explain every line'), true)
  assert.equal(h.hasExplainChecked('- [ ] I can explain every line of this PR'), false)
  assert.equal(h.hasExplainChecked(''), false)
})

test('missingSections lists absent template headings', () => {
  assert.deepEqual(h.missingSections(TEMPLATE_BODY), [])
  assert.deepEqual(h.missingSections('## Summary\n## Why'), [
    'Spec section', 'Changes', 'Money impact', 'Test evidence', 'Screenshots', 'Risks', 'Checklist',
  ])
  assert.deepEqual(h.missingSections('Summary\nWhy').length, 9)
})

test('numberFindings orders critical > important > minor, introduced first, from #1', () => {
  const out = h.numberFindings([
    finding({ severity: 'minor', path: 'b' }),
    finding({ severity: 'critical', origin: 'pre-existing', path: 'a' }),
    finding({ severity: 'important', path: 'c' }),
    finding({ severity: 'critical', origin: 'introduced', path: 'z' }),
  ])
  assert.deepEqual(out.map((f) => `#${f.n} ${f.severity}/${f.origin}`), [
    '#1 critical/introduced', '#2 critical/pre-existing', '#3 important/introduced', '#4 minor/introduced',
  ])
})

test('computeVerdict: thresholds of spec 1-03', () => {
  assert.equal(h.computeVerdict(judgeResult()).verdict, 'MERGEABLE')
  assert.equal(h.computeVerdict(judgeResult({ scores: scores(7), findings: [finding()] })).verdict, 'MERGEABLE AFTER NITS')
  assert.equal(h.computeVerdict(judgeResult({ scores: scores(6) })).verdict, 'NOT MERGEABLE')
})

test('computeVerdict: a failed gate or Money safety below 7 blocks even if the judge says MERGEABLE', () => {
  const r = h.computeVerdict(
    judgeResult({ scores: scores(9, { 'Money safety': 3 }), gates: gates({ noFloat: { status: 'fail', note: 'float in N.php:12' } }) }),
    { moneyOrWebhook: true },
  )
  assert.equal(r.verdict, 'NOT MERGEABLE')
  assert.ok(r.reasons.includes('Money safety scored 3 (< 7)'))
  assert.ok(r.reasons.some((x) => x.startsWith('gate failed: no float')))
})

test('computeVerdict: open introduced important blocks; pre-existing critical does not', () => {
  const blocked = h.computeVerdict(judgeResult({ findings: [finding({ severity: 'important' })] }))
  assert.equal(blocked.verdict, 'NOT MERGEABLE')
  const ok = h.computeVerdict(judgeResult({ findings: [finding({ severity: 'critical', origin: 'pre-existing' })] }))
  assert.equal(ok.verdict, 'MERGEABLE')
  const resolved = h.computeVerdict(judgeResult({ findings: [finding({ severity: 'critical', status: 'resolved' })] }))
  assert.equal(resolved.verdict, 'MERGEABLE')
})

test('computeVerdict: never more lenient than the judge', () => {
  assert.equal(h.computeVerdict(judgeResult({ verdict: 'MERGEABLE AFTER NITS' })).verdict, 'MERGEABLE AFTER NITS')
})

test('validateScores: one entry per dimension, Money safety scored on money PRs', () => {
  assert.deepEqual(h.validateScores(judgeResult(), { moneyOrWebhook: false }), [])
  const missing = judgeResult({ scores: scores(9).filter((s) => s.dimension !== 'Robustness') })
  assert.deepEqual(h.validateScores(missing, { moneyOrWebhook: false }), ['scorecard is missing "Robustness"'])
  const dup = judgeResult({ scores: [...scores(9), { dimension: 'Tests', score: 1, note: '' }] })
  assert.deepEqual(h.validateScores(dup, { moneyOrWebhook: false }), ['scorecard has 2 entries for "Tests"'])
  const moneyNa = judgeResult({ scores: scores(9, { 'Money safety': null }) })
  assert.equal(h.validateScores(moneyNa, { moneyOrWebhook: false }).length, 0)
  assert.equal(h.validateScores(moneyNa, { moneyOrWebhook: true }).length, 1)
  assert.equal(h.computeVerdict(moneyNa, { moneyOrWebhook: true }).verdict, 'NOT MERGEABLE')
})

test('renderReview: header line, inline comments only for findings with a line', () => {
  const { body, comments } = h.renderReview({
    name: 'Laravel Backend Review', marker: '<!-- m -->', files: ['a.php'],
    review: { summary: 's', findings: [finding({ line: 3 }), finding({ line: null, title: 'file-level' })] },
  })
  assert.equal(body.split('\n')[0], 'Laravel Backend Review · automated reviewer (Claude Opus 5.5)')
  assert.equal(body.split('\n')[1], '<!-- m -->')
  assert.ok(body.includes('file-level'))
  assert.equal(comments.length, 1)
  assert.deepEqual({ ...comments[0], body: undefined }, { path: 'routes/web.php', line: 3, side: 'RIGHT', body: undefined })
})

// ---------- scenarios: whole script with stubbed agents ----------
test('all three reviewers run, review and post, then MERGEABLE merges', async () => {
  const { result, labels } = await run({
    ctx: context(['app/Actions/Takes/X.php', 'resources/js/pages/A.vue', 'app/Filament/R.php', 'README.md']),
    judge: judgeResult(),
  })
  assert.equal(result.verdict, 'MERGEABLE')
  assert.equal(result.merged, true)
  for (const id of ['laravel-backend-reviewer', 'inertia-vue-reviewer', 'filament-reviewer']) {
    assert.ok(labels.includes(id) && labels.includes(`post ${id}`), id)
  }
})

test('docs and generated files only: judge only', async () => {
  const { result, labels } = await run({ ctx: context(['README.md', '.claude/specs/x.md', 'composer.lock']), judge: judgeResult() })
  assert.equal(result.judgeOnly, true)
  assert.deepEqual(labels, ['context', 'pr-judge', 'publish verdict'])
})

test('float in money code is NOT MERGEABLE with the gate named', async () => {
  const { result } = await run({
    ctx: context(['app/Support/Money/N.php']),
    judge: judgeResult({ scores: scores(9, { 'Money safety': 3 }), gates: gates({ noFloat: { status: 'fail', note: 'float' } }) }),
  })
  assert.equal(result.verdict, 'NOT MERGEABLE')
  assert.ok(result.verdictReasons.some((r) => r.startsWith('gate failed: no float')))
})

test('money path without the explain-every-line item is NOT MERGEABLE', async () => {
  const { result } = await run({ ctx: context(['app/Actions/Billing/Charge.php'], { body: '## Summary' }), judge: judgeResult() })
  assert.equal(result.verdict, 'NOT MERGEABLE')
  assert.ok(result.verdictReasons.some((r) => r.includes('explain every line')))
})

test('CI overall is derived from the check buckets, not trusted from the agent', async () => {
  const { result } = await run({ ctx: context(['routes/web.php'], { ci: { checks: [{ name: 'ci', bucket: 'fail' }] } }), judge: judgeResult() })
  assert.equal(result.verdict, 'NOT MERGEABLE')
  assert.ok(result.verdictReasons.some((r) => r.startsWith('gate failed: CI')))
})

test('an invalid head SHA stops the run before any review', async () => {
  await assert.rejects(run({ ctx: context(['routes/web.php'], { headSha: 'abc1234' }), judge: judgeResult() }), /invalid SHA/)
})

test('an invalid base SHA or base ref stops the run before any review', async () => {
  await assert.rejects(run({ ctx: context(['routes/web.php'], { baseSha: 'main' }), judge: judgeResult() }), /invalid SHA/)
  await assert.rejects(run({ ctx: context(['routes/web.php'], { baseRef: 'main; rm -rf /' }), judge: judgeResult() }), /invalid base ref/)
})

test('unfetched commits stop the run before any review', async () => {
  await assert.rejects(run({ ctx: context(['routes/web.php'], { objectsFetched: false }), judge: judgeResult() }), /Could not fetch/)
})

test('a reviewer that throws is reported as failed, not N/A', async () => {
  const { result, calls } = await run({
    ctx: context(['routes/web.php', 'resources/js/a.ts']),
    judge: judgeResult(),
    throwFor: ['inertia-vue-reviewer'],
  })
  assert.equal(result.reviewers['inertia-vue-reviewer'], 'failed')
  assert.equal(result.reviewers['filament-reviewer'], 'n/a')
  const judgePrompt = calls.find((c) => c.label === 'pr-judge').prompt
  assert.ok(judgePrompt.includes('the files of every reviewer with status "failed"'))
})

test('a post step that returns null or posted=false keeps the findings but reports the review as not posted', async () => {
  const minor = { path: 'routes/web.php', line: 3, severity: 'minor', title: 't', body: 'b' }
  for (const post of [
    null,
    { posted: false, alreadyPosted: false, reviewUrl: null, inlineComments: 0, movedToBody: 0, error: 'HTTP 422' },
  ]) {
    const { result, logs, calls } = await run({
      ctx: context(['routes/web.php']),
      judge: judgeResult(),
      reviews: { 'laravel-backend-reviewer': [minor] },
      post,
    })
    assert.equal(result.reviewers['laravel-backend-reviewer'], 'ran')
    assert.ok(logs.some((l) => l.includes('review NOT posted')), JSON.stringify(logs))
    const facts = calls.find((c) => c.label === 'pr-judge').prompt
    assert.ok(facts.includes('"title": "t"'), 'reviewer findings still reach the judge')
  }
})

test('MERGEABLE merge step falls back to a direct squash merge and decides from the PR state', async () => {
  const { calls } = await run({ ctx: context(['routes/web.php']), judge: judgeResult() })
  const prompt = calls.find((c) => c.label === 'publish verdict').prompt
  assert.match(prompt, /--squash --auto --delete-branch --match-head-commit a{40}/)
  assert.match(prompt, /enablePullRequestAutoMerge/)
  assert.match(prompt, /"clean status"/)
  assert.match(prompt, /Protected branch rules not configured/)
  assert.match(prompt, /gh pr merge 5 -R JVillator0\/takes --squash --delete-branch --match-head-commit a{40}/)
  assert.match(prompt, /could not determine current branch/)
  assert.match(prompt, /--json state,autoMergeRequest/)
})

test('an invalid scorecard is retried once; still invalid means NOT MERGEABLE', async () => {
  const bad = judgeResult({ scores: scores(9).filter((s) => s.dimension !== 'Correctness') })
  const fixed = await run({ ctx: context(['routes/web.php']), judge: bad, judgeRetry: judgeResult() })
  assert.ok(fixed.labels.includes('pr-judge (retry)'))
  assert.equal(fixed.result.verdict, 'MERGEABLE')
  const still = await run({ ctx: context(['routes/web.php']), judge: bad, judgeRetry: bad })
  assert.equal(still.result.verdict, 'NOT MERGEABLE')
  assert.ok(still.result.verdictReasons.includes('scorecard is missing "Correctness"'))
})

test('MERGEABLE only labels verdict:mergeable once merged or auto-merge is enabled', async () => {
  const { calls } = await run({ ctx: context(['routes/web.php']), judge: judgeResult() })
  const prompt = calls.find((c) => c.label === 'publish verdict').prompt
  assert.match(prompt, /outcome "merged" or "auto-merge-enabled": add `verdict:mergeable`/)
  assert.match(prompt, /any other outcome \(refused or merge error\): add no label/)
})

test('rounds: limit, judgeOnly after NITS, full run after NOT MERGEABLE', async () => {
  const limit = await run({ args: { ...ARGS, round: 4 }, ctx: context(['a.php']), judge: judgeResult() })
  assert.equal(limit.result.verdict, null)
  assert.deepEqual(limit.labels, ['context'])
  const nits = await run({
    args: { ...ARGS, judgeOnly: true },
    ctx: context(['routes/web.php'], { labels: ['verdict:nits'], previousJudgeRounds: 1 }),
    judge: judgeResult(),
  })
  assert.equal(nits.result.round, 2)
  assert.equal(nits.result.judgeOnly, true)
  const notMergeable = await run({
    args: { ...ARGS, judgeOnly: true },
    ctx: context(['routes/web.php'], { labels: ['verdict:not-mergeable'], previousJudgeRounds: 1 }),
    judge: judgeResult(),
  })
  assert.equal(notMergeable.result.judgeOnly, false)
  assert.ok(notMergeable.labels.includes('laravel-backend-reviewer'))
})

test('a merged PR is a no-op', async () => {
  const { result, labels } = await run({ ctx: context([], { state: 'MERGED' }), judge: null })
  assert.equal(result.merged, true)
  assert.deepEqual(labels, ['context'])
})

test('the script never calls Date.now or Math.random', () => {
  assert.doesNotMatch(source, /Date\.now\(|Math\.random\(|new Date\(\)/)
})
