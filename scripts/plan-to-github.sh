#!/usr/bin/env bash
# Mirror .claude/specs/ into GitHub (spec 1-05, D-041): labels, milestones M0-M10, one issue per
# spec "Issues" item, and the public Project "Takes · plan and execution" with Status, Worktree,
# Size and Scope set per item. Then writes .claude/plan/issues.md (spec item -> issue number).
#
# Idempotent: labels, milestones and issues that already exist (issues matched by their "[key] "
# title prefix) are skipped; Project field values are only filled when empty, so a re-run never
# undoes a status someone moved by hand. Exception: if the Status field's options differ from
# Todo / In progress / In review / Done, the script rewrites them, and GitHub then clears every
# item's Status; it refuses unless --reset-status is passed.
#
# Parsing lives in scripts/plan-to-github.php (prints the plan as JSON); this script only talks
# to GitHub through `gh` (needs the `repo` and `project` scopes).
#
# Usage: scripts/plan-to-github.sh [--dry-run] [--reset-status] [--repo owner/name] [--owner login]
set -euo pipefail

REPO="JVillator0/takes"
OWNER="JVillator0"
PROJECT_TITLE="Takes · plan and execution"
STATUS_OPTIONS=("Todo" "In progress" "In review" "Done")
STATUS_COLORS=("GRAY" "YELLOW" "BLUE" "GREEN")
DRY_RUN=0
RESET_STATUS=0
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAPPING_FILE="$ROOT/.claude/plan/issues.md"

usage() { sed -n '2,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --reset-status) RESET_STATUS=1 ;;
    --repo) REPO="$2"; shift ;;
    --owner) OWNER="$2"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

for cmd in gh jq php; do
  command -v "$cmd" >/dev/null || { echo "missing dependency: $cmd" >&2; exit 1; }
done

say() { printf '%s\n' "$*"; }
plan() { say "  [dry-run] $*"; }
dry() { [[ "$DRY_RUN" -eq 1 ]]; }

PLAN="$(php "$ROOT/scripts/plan-to-github.php" --repo "$REPO")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
RECORDS="$WORK/records.tsv" # key, number, url, title, milestone, worktree, size, scope
: >"$RECORDS"
created_labels=0 created_milestones=0 created_issues=0 added_items=0 set_values=0

dry && say "DRY RUN: nothing is written to GitHub or to $MAPPING_FILE"
say "Repository $REPO · Project owner $OWNER"

# --- 1. Labels -------------------------------------------------------------------------------
say ""
say "== Labels"
existing_labels="$(gh label list --repo "$REPO" --limit 500 --json name --jq '.[].name')"
while IFS=$'\t' read -r name color description; do
  if grep -qxF -- "$name" <<<"$existing_labels"; then
    say "  exists   $name"
    continue
  fi
  if dry; then
    plan "create label $name (#$color) \"$description\""
  else
    gh label create "$name" --repo "$REPO" --color "$color" --description "$description" >/dev/null
    say "  created  $name"
  fi
  created_labels=$((created_labels + 1))
done < <(jq -r '.labels[] | [.name, .color, .description] | @tsv' <<<"$PLAN")

# --- 2. Milestones ---------------------------------------------------------------------------
say ""
say "== Milestones"
existing_milestones="$(gh api "repos/$REPO/milestones?state=all&per_page=100" --jq '.[].title')"
milestone_count="$(jq '.milestones | length' <<<"$PLAN")"
for ((i = 0; i < milestone_count; i++)); do
  title="$(jq -r ".milestones[$i].title" <<<"$PLAN")"
  state="$(jq -r ".milestones[$i].state" <<<"$PLAN")"
  description="$(jq -r ".milestones[$i].description" <<<"$PLAN")"
  if grep -qxF -- "$title" <<<"$existing_milestones"; then
    say "  exists   $title"
    continue
  fi
  if dry; then
    plan "create milestone \"$title\" (state: $state)"
    sed 's/^/      | /' <<<"$description"
  else
    gh api -X POST "repos/$REPO/milestones" -f title="$title" -f state="$state" \
      -f description="$description" --silent
    say "  created  $title ($state)"
  fi
  created_milestones=$((created_milestones + 1))
done

# --- 3. Issues -------------------------------------------------------------------------------
say ""
say "== Issues"
existing_issues="$(gh issue list --repo "$REPO" --state all --limit 1000 --json number,title,url)"
issue_count="$(jq '.issues | length' <<<"$PLAN")"
for ((i = 0; i < issue_count; i++)); do
  issue="$(jq -c ".issues[$i]" <<<"$PLAN")"
  key="$(jq -r .key <<<"$issue")"
  title="$(jq -r .title <<<"$issue")"
  milestone="$(jq -r .milestone <<<"$issue")"
  labels="$(jq -r '.labels | join(",")' <<<"$issue")"
  worktree="$(jq -r .worktree <<<"$issue")"
  size="$(jq -r .size <<<"$issue")"
  scope="$(jq -r .scope <<<"$issue")"
  existing="$(jq -r --arg p "[$key] " 'map(select(.title | startswith($p))) | first // empty | "\(.number)\t\(.url)"' <<<"$existing_issues")"

  if [[ -n "$existing" ]]; then
    number="${existing%%$'\t'*}"
    url="${existing#*$'\t'}"
    say "  exists   #$number $title"
  elif dry; then
    number="new" url=""
    plan "create issue \"$title\""
    say "      milestone: $milestone · labels: $labels · worktree: $worktree · size: $size · scope: $scope"
    jq -r .body <<<"$issue" | sed 's/^/      | /'
    created_issues=$((created_issues + 1))
  else
    jq -r .body <<<"$issue" >"$WORK/body.md"
    url="$(gh issue create --repo "$REPO" --title "$title" --body-file "$WORK/body.md" \
      --milestone "$milestone" --label "$labels")"
    number="${url##*/}"
    say "  created  #$number $title"
    created_issues=$((created_issues + 1))
    sleep 1 # GitHub asks for >= 1 s between content-creating requests (secondary rate limit)
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$key" "$number" "$url" "$title" "$milestone" \
    "$worktree" "$size" "$scope" >>"$RECORDS"
done

# --- 4. Project ------------------------------------------------------------------------------
say ""
say "== Project \"$PROJECT_TITLE\""
project="$(gh project list --owner "$OWNER" --format json --limit 200 \
  | jq -c --arg t "$PROJECT_TITLE" '.projects | map(select(.title == $t)) | first // empty')"
if [[ -z "$project" ]]; then
  if dry; then
    plan "create Project \"$PROJECT_TITLE\" (owner $OWNER), link it to $REPO, make it PUBLIC"
    plan "fields: Status (${STATUS_OPTIONS[*]}), Worktree (main a b c d e), Size (S M L), Scope (Required Stretch)"
    plan "add $(wc -l <"$RECORDS" | tr -d ' ') issues; set Status=Todo and Worktree/Size/Scope from the plan"
    plan "write $MAPPING_FILE"
    say ""
    say "Summary (dry run): $created_labels labels, $created_milestones milestones, $created_issues issues to create"
    exit 0
  fi
  project="$(gh project create --owner "$OWNER" --title "$PROJECT_TITLE" --format json)"
  say "  created  project #$(jq -r .number <<<"$project")"
else
  say "  exists   project #$(jq -r .number <<<"$project")"
fi
project_number="$(jq -r .number <<<"$project")"
project_id="$(jq -r .id <<<"$project")"

if dry; then
  plan "ensure the Project is linked to $REPO, PUBLIC, and has the Status/Worktree/Size/Scope fields"
else
  repo_owner="${REPO%%/*}" repo_name="${REPO#*/}"
  linked="$(gh api graphql -f query='query($o:String!,$n:String!){repository(owner:$o,name:$n){projectsV2(first:100){nodes{id}}}}' \
    -f o="$repo_owner" -f n="$repo_name" --jq '.data.repository.projectsV2.nodes[].id')"
  if grep -qxF -- "$project_id" <<<"$linked"; then
    say "  exists   link to $REPO"
  else
    gh project link "$project_number" --owner "$OWNER" --repo "$REPO" >/dev/null
    say "  linked   to $REPO"
  fi
  gh project edit "$project_number" --owner "$OWNER" --visibility PUBLIC \
    --description "Specs → milestones → issues → PRs for $REPO (spec 1-05)." \
    --readme "The plan and its execution for https://github.com/$REPO. Specs live in \`.claude/specs/\` (D-041); each milestone is one spec, each issue one item of a spec's Issues list, and every PR closes an issue. Generated by \`scripts/plan-to-github.sh\`." >/dev/null
  say "  set      visibility PUBLIC, description, readme"
fi

fields() { gh project field-list "$project_number" --owner "$OWNER" --format json --limit 100; }
field_json="$(fields)"

ensure_field() { # name, comma-separated options
  if jq -e --arg n "$1" '.fields | any(.name == $n)' <<<"$field_json" >/dev/null; then
    say "  exists   field $1"
  elif dry; then
    plan "create field $1 ($2)"
  else
    gh project field-create "$project_number" --owner "$OWNER" --name "$1" \
      --data-type SINGLE_SELECT --single-select-options "$2" >/dev/null
    say "  created  field $1 ($2)"
  fi
}
ensure_field "Worktree" "main,a,b,c,d,e"
ensure_field "Size" "S,M,L"
ensure_field "Scope" "Required,Stretch"

# The built-in Status field ships with Todo / In Progress / Done; spec 1-05 wants In review too.
# Replacing the options resets values already set on that field, so it only runs when they differ.
wanted_status="$(IFS=,; echo "${STATUS_OPTIONS[*]}")"
current_status="$(jq -r '.fields[] | select(.name == "Status") | [.options[].name] | join(",")' <<<"$field_json")"
if [[ "$current_status" == "$wanted_status" ]]; then
  say "  exists   field Status ($wanted_status)"
elif dry; then
  plan "set Status options to $wanted_status (currently: $current_status)"
elif [[ "$current_status" != "Todo,In Progress,Done" && "$RESET_STATUS" -ne 1 ]]; then
  echo "Status options are '$current_status', not the GitHub default; rewriting them clears every item's Status. Re-run with --reset-status to do it anyway." >&2
  exit 1
else
  status_field_id="$(jq -r '.fields[] | select(.name == "Status") | .id' <<<"$field_json")"
  options=""
  for idx in "${!STATUS_OPTIONS[@]}"; do
    options+="{name:\"${STATUS_OPTIONS[$idx]}\",color:${STATUS_COLORS[$idx]},description:\"\"},"
  done
  gh api graphql -f query="mutation{updateProjectV2Field(input:{fieldId:\"$status_field_id\",singleSelectOptions:[${options%,}]}){projectV2Field{...on ProjectV2SingleSelectField{id}}}}" >/dev/null
  say "  updated  field Status ($current_status -> $wanted_status)"
fi
field_json="$(fields)"

field_id() { jq -r --arg f "$1" '.fields[] | select(.name == $f) | .id' <<<"$field_json"; }
option_id() { jq -r --arg f "$1" --arg o "$2" '.fields[] | select(.name == $f) | .options[] | select(.name == $o) | .id' <<<"$field_json"; }

# --- 5. Project items ------------------------------------------------------------------------
say ""
say "== Project items"
items_json="$(gh project item-list "$project_number" --owner "$OWNER" --format json --limit 1000)"
while IFS=$'\t' read -r key number url title milestone worktree size scope; do
  item="$(jq -c --arg u "$url" '.items | map(select(.content.url == $u)) | first // empty' <<<"$items_json")"
  if [[ -n "$item" ]]; then
    item_id="$(jq -r .id <<<"$item")"
  elif dry; then
    plan "add #$number $title (Status Todo, Worktree $worktree, Size $size, Scope $scope)"
    continue
  else
    item_id="$(gh project item-add "$project_number" --owner "$OWNER" --url "$url" --format json --jq .id)"
    item="{}"
    added_items=$((added_items + 1))
  fi
  changed=""
  for pair in "Status=Todo" "Worktree=$worktree" "Size=$size" "Scope=$scope"; do
    field="${pair%%=*}" value="${pair#*=}"
    current="$(jq -r --arg f "$(tr '[:upper:]' '[:lower:]' <<<"$field")" '.[$f] // empty' <<<"$item")"
    [[ -n "$current" ]] && continue
    if dry; then
      plan "set #$number $field=$value"
      continue
    fi
    gh project item-edit --id "$item_id" --project-id "$project_id" --field-id "$(field_id "$field")" \
      --single-select-option-id "$(option_id "$field" "$value")" >/dev/null
    changed+=" $field=$value"
    set_values=$((set_values + 1))
  done
  say "  #$number $key${changed:+ ·$changed}"
done <"$RECORDS"

# --- 6. Mapping file -------------------------------------------------------------------------
if dry; then
  plan "write $MAPPING_FILE"
else
  {
    say "# Spec item → GitHub issue"
    say ""
    say "Generated by \`scripts/plan-to-github.sh\` from \`.claude/specs/\`; do not edit by hand."
    say "PRs reference these numbers with \`Closes #N\` (spec 1-05)."
    say "Project: $(jq -r .url <<<"$project")"
    say ""
    say "M2 Product design has no issues: it is closed, its output is \`.claude/specs/2-product-design/\`."
    current_milestone=""
    while IFS=$'\t' read -r key number url title milestone worktree size scope; do
      if [[ "$milestone" != "$current_milestone" ]]; then
        current_milestone="$milestone"
        say ""
        say "## $milestone"
        say ""
        say "| Spec item | Issue | Title | Worktree | Size | Scope |"
        say "|---|---|---|---|---|---|"
      fi
      say "| $key | [#$number]($url) | ${title#\[*\] } | $worktree | $size | $scope |"
    done <"$RECORDS"
  } >"$MAPPING_FILE"
  say "  wrote $MAPPING_FILE"
fi

say ""
say "Summary: created $created_labels labels, $created_milestones milestones, $created_issues issues; added $added_items Project items; set $set_values field values."
say "Project: $(jq -r .url <<<"$project")"
