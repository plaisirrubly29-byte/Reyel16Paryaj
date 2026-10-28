#!/usr/bin/env bash
# Protège main et develop (tâche P1-02) : PR obligatoire, 1 revue (CODEOWNERS), CI verte, pas de push direct.
# Usage : bash scripts/github/protect-branches.sh [owner/repo]   (nécessite gh connecté avec un compte admin)
set -euo pipefail

REPO="${1:-plaisirrubly29-byte/Reyel16Paryaj}"

# Noms des jobs de .github/workflows/ci.yml (doivent réussir avant le merge).
read -r -d '' PROTECTION <<'JSON' || true
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["Backend (Laravel)", "Contrat API (OpenAPI)", "Frontend (React + TypeScript)"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "require_code_owner_reviews": true,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "required_linear_history": false,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": true
}
JSON

for BRANCH in main develop; do
  echo "→ Protection de ${REPO}@${BRANCH}"
  gh api --method PUT "repos/${REPO}/branches/${BRANCH}/protection" \
    -H "Accept: application/vnd.github+json" --input - <<<"$PROTECTION" >/dev/null
done

echo "OK. Vérifier : gh api repos/${REPO}/branches/main/protection --jq '.required_pull_request_reviews'"
