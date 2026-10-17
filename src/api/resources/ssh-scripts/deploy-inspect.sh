# Reads what's already deployed in a folder deploy.me didn't set up, so it
# can be adopted as the live release. Inputs: TARGET, STACK, VARS (the compose
# file's image pin variables, one per line). Changes nothing.
set -o pipefail
cd -- "$TARGET" 2>/dev/null || { echo "✗ $TARGET isn't on the server"; exit 3; }

if [ -e .git ] && command -v git >/dev/null 2>&1; then
  if sha=$(git -c safe.directory='*' rev-parse HEAD 2>/dev/null); then
    branch=$(git -c safe.directory='*' symbolic-ref --quiet --short HEAD 2>/dev/null || true)
    echo "✓ A git checkout at ${sha:0:7}${branch:+ on $branch}"
    echo "::deployme::commit=$sha"
    echo "::deployme::subject=$(git -c safe.directory='*' log -1 --format=%s 2>/dev/null)"
    echo "::deployme::branch=$branch"
  fi
fi

# What the deployment that put the files there recorded: the commit it
# deployed, and the one before it (a project's own deploy script writes this).
if [ -f .deploy-state ]; then
  current=$(sed -n 's/^CURRENT_SHA=//p' .deploy-state | tail -n 1 | tr -d "\"'")
  previous=$(sed -n 's/^PREVIOUS_SHA=//p' .deploy-state | tail -n 1 | tr -d "\"'")
  if [ -n "$current" ]; then
    echo "✓ .deploy-state says CURRENT_SHA=${current:0:7}"
    echo "::deployme::state_current=$current"
  fi
  if [ -n "$previous" ]; then
    echo "· .deploy-state says the deploy before it was ${previous:0:7}"
    echo "::deployme::state_previous=$previous"
  fi
fi

# The image tags the stack runs from, as the folder's env file sets them: they
# become the adopted release's pins, so a rollback has something to go back to.
if [ -f .env ]; then
  pins=""
  while IFS= read -r var; do
    [ -n "$var" ] || continue
    value=$(sed -n "s/^${var}=//p" .env | tail -n 1 | tr -d "\"'")
    [ -n "$value" ] || continue
    echo "✓ $var=$value in .env"
    pins="$pins$var=$value|"
  done <<< "${VARS:-}"
  [ -n "$pins" ] && echo "::deployme::pins=$pins"
fi

if [ -n "$STACK" ] && command -v docker >/dev/null 2>&1; then
  status=$(docker compose ls --all --format json 2>/dev/null | tr -d '[]' | sed 's/},{/}\n{/g' | sed -n "s/.*\"Name\":\"$STACK\",\"Status\":\"\([^\"]*\)\".*/\1/p")
  if [ -n "$status" ]; then
    echo "✓ Stack $STACK is $status"
    echo "::deployme::stack=$status"
  else
    echo "! No Compose stack named $STACK is running"
  fi
fi

count=$(find . -mindepth 1 -maxdepth 1 ! -name .deploy | wc -l | tr -d ' ')
echo "✓ $count item(s) in $TARGET"
echo "::deployme::files=$count"
