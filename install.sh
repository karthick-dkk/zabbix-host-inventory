#!/usr/bin/env bash
# Install or update the Host Inventory Zabbix module, in one step.
#
#   sudo ./install.sh                                          # Zabbix frontend from packages
#   ZABBIX_URL=https://zabbix.example.com ZABBIX_TOKEN=… sudo ./install.sh     # …and enable it
#   ./install.sh --modules-dir /srv/zabbix/modules --no-data-dir               # Docker: a mounted folder
#
# In order:
#   1. copies this module (without tests, tools and docs) into <modules dir>/host_inventory; what
#      was there first is copied to a dated backup folder. The folder itself is refilled, never
#      replaced, so a Docker bind mount of it keeps working;
#   2. makes the writable data folder (default /var/lib/zabbix-host-inventory), owned by the user
#      the Zabbix frontend's PHP runs as;
#   3. with ZABBIX_URL and ZABBIX_TOKEN (a Super admin API token), or ZABBIX_USER and
#      ZABBIX_PASSWORD: registers and enables the module (as Administration → General → Modules
#      → Scan directory, then Enable, would).
#
# It changes no Zabbix configuration file. Options:
#   --modules-dir DIR   Zabbix's modules folder     (default /usr/share/zabbix/modules)
#   --data-dir DIR      the module's data folder    (default /var/lib/zabbix-host-inventory; set
#                                                    HOST_INVENTORY_DATA_DIR for PHP if you change it)
#   --no-data-dir       leave the data folder to you (Docker: a volume)
#   --web-user USER     who PHP runs as             (default: found from php-fpm, else nginx, www-data, apache)
#   --backup-dir DIR    where the old copy goes     (default /var/backups/zabbix-host-inventory)
#   --insecure          ZABBIX_URL has a self-signed certificate
#   --dry-run           say what would happen, change nothing
set -euo pipefail

SRC=$(cd "$(dirname "$0")" && pwd)
ID=host_inventory
MODULES_DIR=/usr/share/zabbix/modules
DATA_DIR=/var/lib/zabbix-host-inventory
BACKUP_DIR=/var/backups/zabbix-host-inventory
WEB_USER=
DATA=1; DRY=0; INSECURE=0

while [ $# -gt 0 ]; do
  case "$1" in
    --modules-dir) MODULES_DIR=$2; shift 2 ;;
    --data-dir) DATA_DIR=$2; shift 2 ;;
    --no-data-dir) DATA=0; shift ;;
    --web-user) WEB_USER=$2; shift 2 ;;
    --backup-dir) BACKUP_DIR=$2; shift 2 ;;
    --insecure) INSECURE=1; shift ;;
    --dry-run) DRY=1; shift ;;
    -h|--help) sed -n '2,31p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1 (see --help)" >&2; exit 2 ;;
  esac
done

say() { printf '%s\n' "$*"; }
run() { if [ "$DRY" = 1 ]; then say "  would: $*"; else "$@"; fi; }
fail() { say "✗ $*" >&2; exit 1; }

[ -f "$SRC/manifest.json" ] || fail "run this from the module's folder (manifest.json not found)"
[ -d "$MODULES_DIR" ] || fail "$MODULES_DIR does not exist — is the Zabbix frontend here? (--modules-dir)"
[ "$DRY" = 1 ] || [ -w "$MODULES_DIR" ] || fail "cannot write to $MODULES_DIR — run with sudo"
DST=$MODULES_DIR/$ID

# --- 1. the module ------------------------------------------------------------------------
say "== module → $DST"
if [ "$(cd "$SRC" && pwd -P)" = "$( [ -d "$DST" ] && cd "$DST" && pwd -P)" ]; then
  say "  ✓ already in place (this is the modules folder's own copy)"
else
  if [ -d "$DST" ] && [ -n "$(ls -A "$DST")" ]; then
    STAMP=$(date +%Y%m%d-%H%M%S)
    run mkdir -p "$BACKUP_DIR/$STAMP"
    run cp -a "$DST" "$BACKUP_DIR/$STAMP/$ID"
    say "  previous copy kept in $BACKUP_DIR/$STAMP"
  fi
  if [ "$DRY" = 1 ]; then
    say "  would: copy the module (without tests, .git, .github, install.sh, README, package files)"
  else
    STAGE=$(mktemp -d)
    trap 'rm -rf "$STAGE"' EXIT
    (cd "$SRC" && tar -cf - --exclude=./.git --exclude=./.github --exclude=./tests --exclude=./node_modules --exclude=./install.sh \
      --exclude=./README.md --exclude=./package.json --exclude=./package-lock.json --exclude=./.gitignore --exclude='._*' --exclude=.DS_Store .) | (cd "$STAGE" && tar -xf -)
    chmod -R u=rwX,go=rX "$STAGE"
    mkdir -p "$DST"
    find "$DST" -mindepth 1 -delete
    cp -a "$STAGE/." "$DST/"
  fi
  say "  ✓ $ID"
fi

# --- 2. data folder -------------------------------------------------------------------------
if [ "$DATA" = 1 ]; then
  if [ -z "$WEB_USER" ]; then
    WEB_USER=$(ps -eo user=,comm= 2>/dev/null | awk '$2 ~ /php-fpm/ && $1 != "root" {print $1; exit}')
    for u in nginx www-data apache; do [ -n "$WEB_USER" ] && break; id "$u" >/dev/null 2>&1 && WEB_USER=$u; done
  fi
  [ -n "$WEB_USER" ] || fail "cannot tell which user PHP runs as — give --web-user"
  say "== data folder → $DATA_DIR (owner $WEB_USER)"
  run mkdir -p "$DATA_DIR"
  run chown "$WEB_USER" "$DATA_DIR"
  run chmod 0770 "$DATA_DIR"
  [ "$DATA_DIR" = /var/lib/zabbix-host-inventory ] || say "  ! not the default folder: set HOST_INVENTORY_DATA_DIR=$DATA_DIR in PHP's environment (php-fpm pool: env[HOST_INVENTORY_DATA_DIR] = $DATA_DIR)"
fi

# --- 3. register and enable -----------------------------------------------------------------
if [ -n "${ZABBIX_URL:-}" ] && { [ -n "${ZABBIX_TOKEN:-}" ] || [ -n "${ZABBIX_USER:-}" ]; }; then
  say "== enabling in Zabbix at $ZABBIX_URL"
  if [ "$DRY" = 1 ]; then
    say "  would: module.create / module.update (status 1) for $ID"
  else
    INSECURE=$INSECURE ID=$ID python3 - <<'PY'
import json, os, ssl, sys, urllib.request
url = os.environ["ZABBIX_URL"].rstrip("/") + "/api_jsonrpc.php"
ctx = ssl._create_unverified_context() if os.environ.get("INSECURE") == "1" else None
auth = os.environ.get("ZABBIX_TOKEN")
def call(method, params, soft=False):
    h = {"Content-Type": "application/json-rpc"}
    if auth: h["Authorization"] = "Bearer " + auth
    r = json.load(urllib.request.urlopen(urllib.request.Request(url, json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode(), h), context=ctx))
    if "error" in r and not soft: sys.exit(f"  ✗ {method}: {r['error'].get('data')}")
    return r
if not auth:
    auth = call("user.login", {"username": os.environ["ZABBIX_USER"], "password": os.environ.get("ZABBIX_PASSWORD", "")})["result"]
mid = os.environ["ID"]
found = call("module.get", {"filter": {"id": mid}, "output": ["moduleid", "status"]})["result"]
if not found:
    r = call("module.create", {"id": mid, "relative_path": f"modules/{mid}", "status": 1}, soft=True)
    print(f"  ✗ Zabbix does not see modules/{mid}: {r['error'].get('data')}" if "error" in r else "  ✓ registered and enabled")
elif found[0]["status"] != "1":
    call("module.update", {"moduleid": found[0]["moduleid"], "status": 1}); print("  ✓ enabled")
else:
    print("  ✓ already enabled")
PY
  fi
else
  say "== not enabled (no ZABBIX_URL with ZABBIX_TOKEN, or ZABBIX_USER + ZABBIX_PASSWORD). In Zabbix:"
  say "   Administration → General → Modules → Scan directory, then enable \"Host Inventory\"."
fi
say ""
say "Next: reload PHP so its opcode cache drops old code (systemctl reload php-fpm), then open Inventory → Host Inventory."
[ "$DRY" = 1 ] && say "(dry run: nothing was changed)"
exit 0
