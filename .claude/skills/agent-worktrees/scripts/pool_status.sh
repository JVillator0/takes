#!/usr/bin/env bash
# Shows the state of every worktree of one or more repositories: branch, commits ahead of the base,
# uncommitted files, lock reason, and the processes whose working directory is inside it.
# Usage: pool_status.sh [--base staging] <repo-path>...
set -u
base=main
if [ "${1:-}" = "--base" ]; then base=$2; shift 2; fi
[ $# -eq 0 ] && { echo "usage: $0 [--base <branch>] <repo-path>..."; exit 1; }

repos=()
for r in "$@"; do repos+=("$(cd "$r" && pwd -P)"); done
cd /
cwds=$(lsof -a -d cwd -F pcn 2>/dev/null | awk '/^p/{pid=substr($0,2)} /^c/{cmd=substr($0,2)} /^n/{print substr($0,2) "\t" pid "\t" cmd}')

for repo in "${repos[@]}"; do
  git -C "$repo" worktree list --porcelain | awk '/^worktree /{print substr($0,10)}' | while read -r wt; do
    name=$(basename "$wt")
    branch=$(git -C "$wt" branch --show-current 2>/dev/null)
    [ -z "$branch" ] && branch="(detached $(git -C "$wt" rev-parse --short HEAD 2>/dev/null))"
    dirty=$(git -C "$wt" status --short 2>/dev/null | wc -l | tr -d ' ')
    ahead=$(git -C "$wt" rev-list --count "origin/$base..HEAD" 2>/dev/null || echo "?")
    lock=$(git -C "$repo" worktree list --porcelain | awk -v w="$wt" '$0=="worktree " w{f=1;next} /^worktree /{f=0} f&&/^locked/{sub(/^locked ?/,""); print (length($0)?$0:"locked")}')
    procs=$(printf '%s\n' "$cwds" | awk -F'\t' -v w="$wt" '$1==w || index($1, w "/")==1 {print $3 "(" $2 ")"}' | sort -u | tr '\n' ' ')
    printf '%-24s %-42s ahead=%-3s dirty=%-3s %s%s\n' "$name" "$branch" "$ahead" "$dirty" "${lock:+LOCKED: $lock }" "${procs:+procs: $procs}"
  done
done
