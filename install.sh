#!/bin/bash
# Checking Root Access
if [[ $EUID -ne 0 ]]; then
    echo -e "\033[31m[ERROR]\033[0m Please run this script as \033[1mroot\033[0m."
    exit 1
fi

INSTALL_LOG="/tmp/mirza_install.log"

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1
export APT_LISTCHANGES_FRONTEND=none

# ── Progress / ETA state ─────────────────────────────────────
ETA_REMAINING=0   # estimated seconds left for the whole install
STEP_NO=0         # how many steps have started
STEP_TOTAL=0      # total steps planned for this run (0 = unknown)

# Seconds -> "9s" or "2m05s"
_fmt_secs() {
    local s=$1
    [ "$s" -lt 0 ] && s=0
    if [ "$s" -lt 60 ]; then printf '%ds' "$s"; else printf '%dm%02ds' $((s / 60)) $((s % 60)); fi
}

# Filled/empty bar of WIDTH chars at PCT percent
_bar() {
    local pct=$1 width=${2:-14} filled i out=""
    [ "$pct" -gt 100 ] && pct=100; [ "$pct" -lt 0 ] && pct=0
    filled=$(( pct * width / 100 ))
    for ((i = 0; i < width; i++)); do
        if [ "$i" -lt "$filled" ]; then out+="█"; else out+="░"; fi
    done
    printf '%s' "$out"
}

# Expected duration (seconds) for a step, matched by its label.
# Keeps the per-step bar and the overall ETA in sync.
_step_eta() {
    case "$1" in
        "Preparing package manager"*)        echo 5  ;;
        "Adding PHP repository"*|"Retrying PHP repository"*) echo 15 ;;
        "Updating & upgrading"*|"Re-running system update"*) echo 120 ;;
        "Installing base tools"*)            echo 25 ;;
        "Installing PHP "*)                  echo 30 ;;
        "Installing web stack"*)             echo 90 ;;
        "Repairing broken MySQL"*)           echo 90 ;;
        "Re-installing web stack"*)          echo 90 ;;
        "Installing phpMyAdmin"*)            echo 40 ;;
        "Installing extra modules"*)         echo 25 ;;
        "Enabling & starting services"*)     echo 8  ;;
        "Configuring firewall"*)             echo 15 ;;
        "Restarting Apache"*)                echo 5  ;;
        "Setting PHP as the active"*|"Setting PHP "*) echo 6  ;;
        "Downloading Mirza"*)                echo 20 ;;
        "Extracting source files"*)          echo 5  ;;
        "Configuring MySQL root access"*)    echo 10 ;;
        "Opening firewall ports"*)           echo 4  ;;
        "Stopping Apache"*)                  echo 4  ;;
        "Installing Let's Encrypt"*|"Installing certbot"*) echo 25 ;;
        "Requesting SSL certificate"*)       echo 25 ;;
        "Installing Apache certbot plugin"*) echo 25 ;;
        "Configuring SSL on Apache"*)        echo 20 ;;
        "Enabling & starting Apache"*|"Starting Apache"*) echo 5 ;;
        "Configuring Apache virtual hosts"*) echo 6  ;;
        "Creating database & user"*)         echo 5  ;;
        "Setting Telegram webhook"*)         echo 5  ;;
        "Initializing database tables"*)     echo 15 ;;
        *)                                   echo 8  ;;
    esac
}

# Plan the run: count pending steps + total expected time (skips done phases).
plan_eta() {
    STEP_TOTAL=0; ETA_REMAINING=0; STEP_NO=0
    phase_done DEPS    || { STEP_TOTAL=$((STEP_TOTAL + 12)); ETA_REMAINING=$((ETA_REMAINING + 388)); }
    phase_done FILES   || { STEP_TOTAL=$((STEP_TOTAL + 3));  ETA_REMAINING=$((ETA_REMAINING + 40)); }
    phase_done DBROOT  || { STEP_TOTAL=$((STEP_TOTAL + 1));  ETA_REMAINING=$((ETA_REMAINING + 10)); }
    if ! phase_done SSL; then
        if [ -f "/etc/letsencrypt/live/$(state_get DOMAIN)/fullchain.pem" ]; then
            STEP_TOTAL=$((STEP_TOTAL + 1)); ETA_REMAINING=$((ETA_REMAINING + 5))
        else
            STEP_TOTAL=$((STEP_TOTAL + 7)); ETA_REMAINING=$((ETA_REMAINING + 108))
        fi
    fi
    phase_done VHOST   || { STEP_TOTAL=$((STEP_TOTAL + 1)); ETA_REMAINING=$((ETA_REMAINING + 6)); }
    phase_done DB      || { STEP_TOTAL=$((STEP_TOTAL + 1)); ETA_REMAINING=$((ETA_REMAINING + 5)); }
    phase_done WEBHOOK || { STEP_TOTAL=$((STEP_TOTAL + 3)); ETA_REMAINING=$((ETA_REMAINING + 25)); }
}

print_header() {
    echo ""
    echo -e "\033[1;34m╭────────────────────────────────────────────────╮\033[0m"
    printf  "\033[1;34m│\033[0m \033[1;36m%-46s\033[0m \033[1;34m│\033[0m\n" "$1"
    echo -e "\033[1;34m╰────────────────────────────────────────────────╯\033[0m"
}

run_step() {
    local msg="$1"
    local cmd="$2"
    local eta="${3:-$(_step_eta "$msg")}"
    [ "$eta" -lt 1 ] && eta=1
    STEP_NO=$((STEP_NO + 1))
    local counter="$STEP_NO"
    [ "$STEP_TOTAL" -gt 0 ] && counter="$STEP_NO/$STEP_TOTAL"
    : > "$INSTALL_LOG"
    local start; start=$(date +%s)
    bash -c "$cmd" >> "$INSTALL_LOG" 2>&1 &
    local pid=$!
    local frames=("⠋" "⠙" "⠹" "⠸" "⠼" "⠴" "⠦" "⠧" "⠇" "⠏")
    local n=${#frames[@]}
    local i=0
    tput civis 2>/dev/null
    while kill -0 "$pid" 2>/dev/null; do
        local el=$(( $(date +%s) - start ))
        local pct=$(( el * 100 / eta ))
        [ "$pct" -gt 95 ] && pct=95          # don't show full until it really finishes
        local left=$(( eta - el )) lefttxt
        if [ "$left" -gt 0 ]; then lefttxt="~$(_fmt_secs $left) left"; else lefttxt="finishing…"; fi
        local otxt=""
        if [ "$ETA_REMAINING" -gt 0 ]; then
            local orem=$(( ETA_REMAINING - el )); [ "$orem" -lt 0 ] && orem=0
            otxt=" \033[0;37m· total ~$(_fmt_secs $orem)\033[0m"
        fi
        printf "\r\033[K \033[1;33m%s\033[0m \033[0;37m[%s]\033[0m %s  \033[1;36m▕%s▏\033[0m \033[0;37m%s · %s\033[0m%b" \
            "${frames[$i]}" "$counter" "$msg" "$(_bar "$pct" 14)" "$(_fmt_secs $el)" "$lefttxt" "$otxt"
        i=$(( (i + 1) % n ))
        sleep 0.2
    done
    wait "$pid"
    local rc=$?
    local el=$(( $(date +%s) - start ))
    tput cnorm 2>/dev/null
    if [ "$ETA_REMAINING" -gt 0 ]; then
        ETA_REMAINING=$(( ETA_REMAINING - eta )); [ "$ETA_REMAINING" -lt 0 ] && ETA_REMAINING=0
    fi
    # The one place every long step passes through, so the bot's progress bar is
    # drawn from here instead of from seven separate call sites - and a step
    # added to the update later moves the bar without anyone remembering to.
    if [ -n "$MZ_TPL_PROGRESS" ] && [ "$rc" -eq 0 ] && [ "$STEP_TOTAL" -gt 0 ]; then
        _selfupdate_progress $(( STEP_NO * 100 / STEP_TOTAL ))
    fi
    if [ "$rc" -eq 0 ]; then
        printf "\r\033[K \033[1;32m✔\033[0m \033[0;37m[%s]\033[0m %s \033[0;37m(%s)\033[0m\n" "$counter" "$msg" "$(_fmt_secs $el)"
    else
        printf "\r\033[K \033[1;31m✘\033[0m \033[0;37m[%s]\033[0m %s \033[0;37m(%s)\033[0m\n" "$counter" "$msg" "$(_fmt_secs $el)"
    fi
    return "$rc"
}

show_step_error() {
    echo -e "\033[1;31m──────────────── Error details ─────────────────\033[0m"
    tail -n 20 "$INSTALL_LOG" 2>/dev/null
    echo -e "\033[1;31m─────────────────────────────────────────────────\033[0m"
}

# ── Menu UI helpers ──────────────────────────────────────────
C_BORDER=$'\033[38;5;24m'; C_TITLE=$'\033[1;97m'; C_DIM=$'\033[38;5;245m'
C_KEY=$'\033[1;36m';       C_TXT=$'\033[0;37m';   C_OK=$'\033[1;32m'
C_BAD=$'\033[1;31m';       C_WARN=$'\033[1;33m';  C_PROMPT=$'\033[1;36m'
C_ACCENT=$'\033[38;5;39m'; C_LABEL=$'\033[38;5;250m'
CR=$'\033[0m'
UI_W=60   # width of horizontal rules (no right border = never misaligns)

_repeat() { local ch="$1" n="$2" out="" i; for ((i=0;i<n;i++)); do out+="$ch"; done; printf '%s' "$out"; }
# Horizontal rules (left-aligned, no right edge to drift)
_rule()   { printf "  ${C_BORDER}%s${CR}\n" "$(_repeat "─" "$UI_W")"; }
_drule()  { printf "  ${C_ACCENT}%s${CR}\n" "$(_repeat "━" "$UI_W")"; }
# Banner
banner()  {
    echo
    _drule
    printf "  ${C_ACCENT}◆${CR}  ${C_TITLE}MIRZA PRO MAX${CR}\n"
    printf "     ${C_DIM}one bot, a different shop for every language${CR}\n"
    _drule
}
# Menu row. Two forms:
#   _mi "1" "text"                 - plain
#   _mi "1" "Install" "what it does" - name padded, description dimmed
_mi() {
    if [ -n "${3-}" ]; then
        printf "    ${C_KEY}%s${CR} ${C_BORDER}│${CR} ${C_TXT}%-14s${CR}${C_DIM}%b${CR}\n" "$1" "$2" "$3"
    else
        printf "    ${C_KEY}%s${CR} ${C_BORDER}│${CR} ${C_TXT}%b${CR}\n" "$1" "$2"
    fi
}

# ── DNS auto-fix (used early, before any download) ───────────
RESOLV="/etc/resolv.conf"
DNS_SERVERS=("1.1.1.1" "8.8.8.8" "9.9.9.9")

dns_works() {
    getent hosts github.com       >/dev/null 2>&1 && return 0
    getent hosts api.telegram.org >/dev/null 2>&1 && return 0
    return 1
}

ensure_dns() {
    dns_works && return 0
    echo -e "  ${C_WARN}!${CR} ${C_WARN}DNS resolution failed - configuring public DNS...${CR}"
    if [ -L "$RESOLV" ]; then
        rm -f "$RESOLV" 2>/dev/null
    elif [ -f "$RESOLV" ] && [ ! -f "${RESOLV}.mirza.bak" ]; then
        cp -a "$RESOLV" "${RESOLV}.mirza.bak" 2>/dev/null
    fi
    { local d; for d in "${DNS_SERVERS[@]}"; do echo "nameserver $d"; done; } > "$RESOLV" 2>/dev/null
    if command -v resolvectl >/dev/null 2>&1; then
        local ifc; ifc=$(ip route show default 2>/dev/null | awk '/default/{print $5; exit}')
        [ -n "$ifc" ] && resolvectl dns "$ifc" "${DNS_SERVERS[@]}" 2>/dev/null || true
    fi
    sleep 1
    dns_works && { echo -e "  ${C_OK}●${CR} ${C_OK}DNS is now working.${CR}"; return 0; }
    echo -e "  ${C_BAD}●${CR} ${C_BAD}DNS still failing after applying public resolvers.${CR}"
    return 1
}

# Ensure /usr/local/bin/mirza points at the master script
_link_mirza() {
    local master="$1" link="$2"
    chmod +x "$master" 2>/dev/null
    if [ ! -e "$link" ] || [ "$(readlink -f "$link" 2>/dev/null)" != "$(readlink -f "$master" 2>/dev/null)" ]; then
        ln -sf "$master" "$link"
    fi
    chmod +x "$link" 2>/dev/null
}

# Self-update: every run, fetch the latest script from GitHub, validate it,
# install it to /root/install.sh, link it into /usr/local/bin, and re-exec.
function self_update_script() {
    # asking for help must never depend on the network - and neither must the
    # once-a-minute watcher: fetching this script from GitHub every 60 seconds
    # would hammer it for nothing, and rewriting /root/install.sh while bash is
    # still reading that very file is how a half-executed script happens. The
    # watcher gets its new copy the normal way, from the package update_bot
    # unpacks (step 9), not from here.
    for _a in "$@"; do
        case "$_a" in -h|--help|--version|selfupdate-watch) return 0 ;; esac
    done
    local MASTER_PATH="/root/install.sh"
    local BIN_LINK="/usr/local/bin/mirza"
    local URL="https://raw.githubusercontent.com/Alfred-1313/Mirza_Pro_Max/master/install.sh"
    local TEMP_FILE="/tmp/mirzabot_update.sh"

    # Make sure DNS works before reaching GitHub
    ensure_dns >/dev/null 2>&1

    echo -e "\e[33mChecking for the latest script version...\033[0m"
    rm -f "$TEMP_FILE"
    curl -fsSL --max-time 15 -o "$TEMP_FILE" "$URL" 2>/dev/null \
        || wget -q -O "$TEMP_FILE" "$URL" 2>/dev/null

    # Normalize line endings so a CRLF download can never break bash
    [ -f "$TEMP_FILE" ] && sed -i 's/\r$//' "$TEMP_FILE"

    # Validate the download is a complete, valid bash script (not a 404/HTML/partial)
    local valid=0
    if [ -s "$TEMP_FILE" ] \
       && head -n1 "$TEMP_FILE" | grep -q '^#!/bin/bash' \
       && grep -q 'process_arguments' "$TEMP_FILE" \
       && bash -n "$TEMP_FILE" 2>/dev/null; then
        valid=1
    fi

    if [ "$valid" -ne 1 ]; then
        echo -e "\e[91mWarning: could not fetch a valid update (offline / bad download). Using current version.\033[0m"
        rm -f "$TEMP_FILE"
        if [ ! -f "$MASTER_PATH" ]; then
            # No copy installed yet - but if the script we are running is
            # itself valid, use it. Only a genuinely unusable state exits.
            local SELF; SELF=$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null)
            if [ -n "$SELF" ] && [ -r "$SELF" ] && bash -n "$SELF" 2>/dev/null; then
                install -m 0755 "$SELF" "$MASTER_PATH" 2>/dev/null || { cp "$SELF" "$MASTER_PATH"; chmod +x "$MASTER_PATH"; }
                _link_mirza "$MASTER_PATH" "$BIN_LINK"
                echo -e "\e[93mGitHub is unreachable - continuing with this copy of the script.\033[0m"
                return 0
            fi
            echo -e "\e[91mCritical: no usable copy of the script and GitHub is unreachable.\033[0m"
            exit 1
        fi
        _link_mirza "$MASTER_PATH" "$BIN_LINK"
        return 0
    fi

    local LOCAL_HASH REMOTE_HASH
    if [ -f "$MASTER_PATH" ]; then
        LOCAL_HASH=$(md5sum "$MASTER_PATH" | awk '{print $1}')
    else
        LOCAL_HASH="not_installed"
    fi
    REMOTE_HASH=$(md5sum "$TEMP_FILE" | awk '{print $1}')

    if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
        if [ "$LOCAL_HASH" = "not_installed" ]; then
            echo -e "\e[32mInstalling script to the system...\033[0m"
        else
            echo -e "\e[32mNew version found - updating...\033[0m"
        fi
        install -m 0755 "$TEMP_FILE" "$MASTER_PATH" 2>/dev/null || { mv "$TEMP_FILE" "$MASTER_PATH"; chmod +x "$MASTER_PATH"; }
        rm -f "$TEMP_FILE"
        _link_mirza "$MASTER_PATH" "$BIN_LINK"
        echo -e "\e[32mUpdated. Restarting with the latest version...\033[0m"
        sleep 1
        exec bash "$MASTER_PATH" "$@"
    fi

    # Already up to date - just make sure it is linked under /usr/local/bin
    rm -f "$TEMP_FILE"
    _link_mirza "$MASTER_PATH" "$BIN_LINK"
    echo -e "\e[32mScript is up to date.\033[0m"
}
self_update_script "$@"

# ── Repo / paths ─────────────────────────────────────────────
BOT_DIR_DEFAULT="/var/www/html/mirzaprobotconfig"
CONFIG_FILE_DEFAULT="$BOT_DIR_DEFAULT/config.php"
GIT_REPO="Alfred-1313/Mirza_Pro_Max"
IP_CACHE="/tmp/.mirza_server_ip"

# ── Resumable-install state engine ───────────────────────────
# Survives reboots / network drops. Lets a failed install resume
# from the last completed phase instead of starting from scratch.
STATE_DIR="/root/confmirza"
STATE_FILE="$STATE_DIR/.mirza_install_state"

state_init() {
    mkdir -p "$STATE_DIR" 2>/dev/null
    if [ ! -f "$STATE_FILE" ]; then
        : > "$STATE_FILE"
        chmod 600 "$STATE_FILE" 2>/dev/null
    fi
}

# state_set KEY VALUE  -> store a persistent answer (domain/token/etc.)
state_set() {
    state_init
    sed -i "/^$1=/d" "$STATE_FILE" 2>/dev/null
    printf '%s=%s\n' "$1" "$2" >> "$STATE_FILE"
}

# state_get KEY -> echo the stored value (empty if missing)
state_get() {
    [ -f "$STATE_FILE" ] || return 0
    grep -E "^$1=" "$STATE_FILE" 2>/dev/null | tail -1 | cut -d= -f2-
}

# phase_done NAME -> 0 if the phase already completed successfully
phase_done() {
    [ -f "$STATE_FILE" ] && grep -qxF "PHASE:$1" "$STATE_FILE" 2>/dev/null
}

# mark_phase NAME -> record a phase as completed
mark_phase() {
    state_init
    grep -qxF "PHASE:$1" "$STATE_FILE" 2>/dev/null || echo "PHASE:$1" >> "$STATE_FILE"
}

# has_resumable_state -> 0 if an unfinished install is on disk
has_resumable_state() {
    [ -f "$STATE_FILE" ] || return 1
    { grep -q '^PHASE:' "$STATE_FILE" 2>/dev/null || grep -q '^STARTED=' "$STATE_FILE" 2>/dev/null; } \
        && ! phase_done COMPLETE
}

state_clear() { rm -f "$STATE_FILE" 2>/dev/null; }

# ── apt/dpkg recovery ────────────────────────────────────────
# A previous interrupted apt run (or Ubuntu's background
# unattended-upgrades) can hold the dpkg lock, making the next
# apt command hang forever. This waits for any LIVE apt to finish,
# clears locks left by a DEAD process, then repairs dpkg state.
apt_recover() {
    local i=0
    # 1) If a real apt/dpkg is running (e.g. unattended-upgrades), wait for it
    if pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null 2>&1; then
        echo "Another apt/dpkg process is running; waiting up to 3 minutes for it to finish..."
        while pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null 2>&1; do
            sleep 3; i=$((i + 1)); [ "$i" -ge 60 ] && break
        done
    fi
    # 2) Disable Ubuntu auto-update timers during install so they cannot re-grab the lock
    systemctl stop apt-daily.service apt-daily-upgrade.service \
        unattended-upgrades.service >/dev/null 2>&1
    systemctl stop apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1
    # 3) No live holder now -> remove stale locks left by the crashed run
    if ! pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null 2>&1; then
        rm -f /var/lib/apt/lists/lock /var/cache/apt/archives/lock \
              /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend 2>/dev/null
    fi
    # 4) Repair any half-configured packages from the interruption
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a >/dev/null 2>&1
    return 0
}
export -f apt_recover

OS_ID=""; OS_VERSION_ID=""; OS_CODENAME=""; OS_PRETTY=""
detect_os() {
    [ -n "$OS_ID" ] && return 0
    [ -f /etc/os-release ] || return 1
    local fields
    fields=$(. /etc/os-release 2>/dev/null; printf '%s\t%s\t%s\t%s' \
        "${ID:-}" "${VERSION_ID:-}" "${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}" "${PRETTY_NAME:-unknown}")
    IFS=$'\t' read -r OS_ID OS_VERSION_ID OS_CODENAME OS_PRETTY <<< "$fields"
    return 0
}
export -f detect_os

os_major() {
    detect_os
    local m="${OS_VERSION_ID%%.*}"
    case "$m" in ''|*[!0-9]*) echo 0 ;; *) echo "$m" ;; esac
}
export -f os_major

php_ppa_has_series() {
    [ -n "$1" ] || return 1
    local try
    for try in 1 2 3; do
        curl -fsSL --max-time 10 -o /dev/null \
            "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/$1/Release" 2>/dev/null && return 0
        sleep 2
    done
    return 1
}
export -f php_ppa_has_series

php_repo_disable() {
    local f n=0
    for f in /etc/apt/sources.list.d/*ondrej*php*.sources /etc/apt/sources.list.d/*ondrej*php*.list; do
        [ -f "$f" ] || continue
        mv -f "$f" "$f.disabled-by-mirza" && n=$((n + 1))
    done
    [ "$n" -gt 0 ]
}
export -f php_repo_disable

setup_php_repo() {
    detect_os
    export DEBIAN_FRONTEND=noninteractive
    # add-apt-repository lives in software-properties-common - minimal cloud
    # images (and the 26.04 minimal image in particular) do not ship it.
    if ! command -v add-apt-repository >/dev/null 2>&1; then
        apt-get update -o DPkg::Lock::Timeout=180 >/dev/null 2>&1
        apt-get install -y software-properties-common ca-certificates curl gnupg \
            -o DPkg::Lock::Timeout=180 || return 1
    fi
    add-apt-repository -y ppa:ondrej/php || \
        LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php || return 1

    # Does the PPA actually build for this release? Pinning to an older series
    if [ -n "$OS_CODENAME" ] && ! php_ppa_has_series "$OS_CODENAME"; then
        echo "ondrej/php publishes no packages for '$OS_CODENAME' - disabling the PPA and using the PHP shipped with $OS_PRETTY."
        php_repo_disable || echo "Warning: no ondrej/php source file found to disable."
    fi
    return 0
}
export -f setup_php_repo

export PHP_VER_CANDIDATES="8.2 8.3 8.4 8.5"

# 0 when apt has an installable candidate for this package.
_apt_has_candidate() {
    local cand
    cand=$(apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/{print $2; exit}')
    [ -n "$cand" ] && [ "$cand" != "(none)" ]
}
export -f _apt_has_candidate

resolve_php_ver() {
    local v
    for v in $PHP_VER_CANDIDATES; do
        if _apt_has_candidate "php$v" && _apt_has_candidate "libapache2-mod-php$v"; then
            echo "$v"; return 0
        fi
    done
    echo "8.2"
    return 1
}
export -f resolve_php_ver

# Configure MySQL root login (all output captured by run_step's log).
setup_mysql_root() {
    sudo mkdir -p /root/confmirza || return 1
    touch /root/confmirza/dbrootmirza.txt || return 1
    sudo chmod -R 777 /root/confmirza/dbrootmirza.txt || return 1
    local randomdbpasstxt passs userrr RANDOM_NUMBER
    randomdbpasstxt=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-8)
    RANDOM_NUMBER=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-12)
    echo "\$user = 'root';"               >> /root/confmirza/dbrootmirza.txt
    echo "\$pass = '${randomdbpasstxt}';" >> /root/confmirza/dbrootmirza.txt
    echo "\$path = '${RANDOM_NUMBER}';"   >> /root/confmirza/dbrootmirza.txt
    passs=$(grep '$pass' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
    userrr=$(grep '$user' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
    local alter_ok=0
    if sudo mysql -u "$userrr" -p"$passs" -e "alter user '$userrr'@'localhost' identified with mysql_native_password by '$passs';FLUSH PRIVILEGES;"; then
        alter_ok=1
    elif sudo mysql -e "alter user '$userrr'@'localhost' identified with mysql_native_password by '$passs';FLUSH PRIVILEGES;"; then
        alter_ok=1
    elif sudo mysql -e "alter user '$userrr'@'localhost' identified with caching_sha2_password by '$passs';FLUSH PRIVILEGES;"; then
        alter_ok=1
    elif sudo mysql -e "alter user '$userrr'@'localhost' identified by '$passs';FLUSH PRIVILEGES;"; then
        alter_ok=1
    fi
    if [ "$alter_ok" -eq 1 ]; then
        echo "SELECT 1" | mysql -u"$userrr" -p"$passs" >/dev/null 2>&1 && return 0
    fi

    local dropin_dir=""
    local d
    for d in /etc/mysql/mysql.conf.d /etc/mysql/mariadb.conf.d /etc/mysql/conf.d; do
        [ -d "$d" ] && { dropin_dir="$d"; break; }
    done
    [ -n "$dropin_dir" ] || return 1
    local dropin="$dropin_dir/zz-mirza-recovery.cnf"
    printf '[mysqld]\nskip-grant-tables\n' | sudo tee "$dropin" >/dev/null || return 1
    sudo systemctl restart mysql
    sudo mysql <<EOF
FLUSH PRIVILEGES;
DROP USER IF EXISTS 'root'@'localhost';
CREATE USER 'root'@'localhost' IDENTIFIED BY '${passs}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
    sudo rm -f "$dropin"
    # Older installer versions appended the option to mysqld.cnf directly.
    sudo sed -i '/^skip-grant-tables/d' /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null
    sudo systemctl restart mysql
    echo "SELECT 1" | mysql -u"$userrr" -p"$passs" >/dev/null 2>&1 || return 1
    return 0
}
export -f setup_mysql_root

# Install Composer to /usr/local/bin/composer when it is not already available.
# The installer is verified against the official signature before it is run.
ensure_composer() {
    if command -v composer >/dev/null 2>&1; then
        return 0
    fi

    local php_bin setup expected actual
    php_bin="$(command -v php)" || return 1
    setup="$(mktemp /tmp/composer-setup.XXXXXX.php)"

    expected="$("$php_bin" -r "echo @file_get_contents('https://composer.github.io/installer.sig');" 2>/dev/null | tr -d '[:space:]')"
    if ! "$php_bin" -r "exit(@copy('https://getcomposer.org/installer', '$setup') ? 0 : 1);"; then
        rm -f "$setup"
        echo "Failed to download the Composer installer." >&2
        return 1
    fi

    actual="$("$php_bin" -r "echo hash_file('sha384', '$setup');" 2>/dev/null | tr -d '[:space:]')"
    if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
        rm -f "$setup"
        echo "Composer installer signature mismatch - refusing to run it." >&2
        return 1
    fi

    "$php_bin" "$setup" --quiet --install-dir=/usr/local/bin --filename=composer
    local rc=$?
    rm -f "$setup"
    [ "$rc" -eq 0 ] && command -v composer >/dev/null 2>&1
}
export -f ensure_composer

# Detect the active CLI PHP major.minor (e.g. 8.2). Empty on failure.
active_php_ver() {
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null
}
export -f active_php_ver

ensure_php_exts_for_composer() {
    local ver pkgs
    ver="$(active_php_ver)"
    if [ -z "$ver" ]; then
        echo "PHP CLI not found - cannot install required extensions." >&2
        return 1
    fi

    if php -m 2>/dev/null | grep -qi '^mbstring$' \
        && php -m 2>/dev/null | grep -qi '^dom$'; then
        return 0
    fi

    pkgs="php${ver}-mbstring php${ver}-xml php${ver}-zip php${ver}-gd php${ver}-curl php${ver}-intl php${ver}-bcmath"
    echo "Ensuring PHP ${ver} extensions for Composer: ${pkgs}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y $pkgs || {
        echo "Failed to install PHP ${ver} extensions required by Composer." >&2
        return 1
    }

    if ! php -m 2>/dev/null | grep -qi '^mbstring$' \
        || ! php -m 2>/dev/null | grep -qi '^dom$'; then
        echo "PHP ${ver} is missing mbstring and/or dom after package install." >&2
        return 1
    fi
    return 0
}
export -f ensure_php_exts_for_composer

# Build vendor/ from composer.json + composer.lock. vendor/ is not shipped in the
# release archive, so this must run on every fresh install and update.
install_php_deps() {
    local dir="$1"

    if [ ! -f "$dir/composer.json" ]; then
        echo "No composer.json in $dir - skipping dependency installation."
        return 0
    fi

    ensure_php_exts_for_composer || return 1
    ensure_composer || return 1

    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 \
        composer install --working-dir="$dir" \
        --no-dev --optimize-autoloader --prefer-dist --no-progress || return 1

    if [ ! -f "$dir/vendor/autoload.php" ]; then
        echo "composer install finished but $dir/vendor/autoload.php is missing." >&2
        return 1
    fi

    chown -R www-data:www-data "$dir/vendor" 2>/dev/null
    return 0
}
export -f install_php_deps

# True if a package is installed and configured.
_pkg_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }

_pkg_installed_glob() {
    dpkg-query -W -f='${Package} ${Status}\n' "$1" 2>/dev/null | grep -q 'install ok installed'
}

# Refuse to install on a server that already has conflicting software.
# Only runs on a brand-new install (never on resume / Mirza's own partial state).
precheck_fresh_server() {
    # A package being installed is not a reason to refuse - an empty LAMP
    # stack is fine and gets reconfigured. What blocks the install is
    # something that is actually IN USE and would be taken over: another
    # site, another database, a running web server, a panel.
    # There is no way past this on purpose: the installer resets the MySQL
    # root password, rewrites the Apache config and reinstalls phpMyAdmin.
    # Each blocker is "label|name|detail" so the name can be shown in red.
    local blockers=() notes=()

    # --- panels that bind the same ports and rewrite the same configs ---
    { [ -d /opt/marzban ] || [ -d /var/lib/marzban ]; } && blockers+=("Panel|Marzban|owns ports 80/443")
    { [ -d /opt/hiddify-manager ] || [ -d /opt/hiddify-config ]; } && blockers+=("Panel|Hiddify|owns ports 80/443")
    { [ -d /etc/x-ui ] || [ -d /usr/local/x-ui ]; } && notes+=("x-ui is installed - make sure it is not using port 80 or 443")

    # --- nginx: only a problem if it is actually serving ---
    if _pkg_installed nginx || _pkg_installed nginx-core || _pkg_installed nginx-full; then
        if systemctl is-active nginx >/dev/null 2>&1; then
            blockers+=("Web server|nginx|running, would fight Apache for port 80/443")
        else
            notes+=("nginx is installed but stopped - leaving it alone")
        fi
    fi

    # --- apache: only a problem if it already serves someone else's site ---
    if _pkg_installed apache2; then
        local site
        for site in $(ls -1 /etc/apache2/sites-enabled/ 2>/dev/null | grep -vE '^(000-default|default-ssl)'); do
            blockers+=("Apache site|${site}|already served from this server")
        done
        if [ ${#blockers[@]} -eq 0 ]; then
            notes+=("Apache is installed with no sites of its own - it will be configured for the bot")
        fi
    fi

    # --- mysql/mariadb: only a problem if it holds real databases ---
    if _pkg_installed mysql-server || _pkg_installed_glob 'mysql-server-[0-9]*' \
       || _pkg_installed mariadb-server || _pkg_installed_glob 'mariadb-server-[0-9]*'; then
        local dbs db owner
        dbs=$(mysql -N -B -e "SHOW DATABASES;" 2>/dev/null \
            | grep -vxE '(mysql|information_schema|performance_schema|sys|phpmyadmin)')
        if [ -n "$dbs" ]; then
            for db in $dbs; do
                # name the service that owns it, when one is obvious
                owner=$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null \
                        | awk '{print $1}' | grep -iE "^${db}(\.service)?$" | head -1)
                if [ -n "$owner" ]; then
                    blockers+=("MySQL database|${db}|in use by ${owner}")
                else
                    blockers+=("MySQL database|${db}|holds data that is not the bot's")
                fi
            done
        else
            if mysql -e "SELECT 1;" >/dev/null 2>&1; then
                notes+=("MySQL is installed and empty - the bot's database will be created in it")
            else
                notes+=("MySQL is installed but its root login is password-protected - the installer will ask for it")
            fi
        fi
    fi

    _pkg_installed phpmyadmin && notes+=("phpMyAdmin is installed - it will be reconfigured for Apache")

    # --- nothing in the way ---
    if [ ${#blockers[@]} -eq 0 ]; then
        if [ ${#notes[@]} -gt 0 ]; then
            _sec "Existing software"
            local n
            for n in "${notes[@]}"; do printf "    ${C_DIM}·${CR} ${C_DIM}%s${CR}\n" "$n"; done
            echo ""
        fi
        return 0
    fi

    # --- blocked ---
    clear 2>/dev/null || true
    banner
    _sec "Cannot install on this server"
    printf "    ${C_BAD}●${CR} ${C_TXT}Something here is already in use and would be taken over:${CR}\n"
    echo ""
    local b label name detail
    for b in "${blockers[@]}"; do
        label="${b%%|*}"
        name="${b#*|}"; name="${name%%|*}"
        detail="${b##*|}"
        printf "      ${C_DIM}%-16s${CR} ${C_BAD}%s${CR}  ${C_DIM}%s${CR}\n" "$label" "$name" "$detail"
    done
    echo ""
    printf "    ${C_TXT}Installing Mirza resets the MySQL root password, rewrites the Apache${CR}\n"
    printf "    ${C_TXT}configuration and reinstalls phpMyAdmin. On the above that breaks${CR}\n"
    printf "    ${C_TXT}what is running now, so the installer stops here.${CR}\n"
    echo ""
    printf "    ${C_DIM}Install on a clean server, or remove the software listed above first.${CR}\n"
    printf "    ${C_DIM}If this server already runs Mirza, choose ${CR}${C_KEY}2 (Update)${CR}${C_DIM} instead.${CR}\n"
    return 1
}


repair_mysql() {
    export DEBIAN_FRONTEND=noninteractive
    systemctl stop mysql 2>/dev/null
    # 1) Gentle fix first
    dpkg --configure -a >/dev/null 2>&1
    apt-get install -f -y >/dev/null 2>&1
    if dpkg-query -W -f='${Package} ${Status}\n' 'mysql-server-[0-9]*' 2>/dev/null | grep -q 'install ok installed'; then
        return 0
    fi
    # 2) Hard reset: purge MySQL and wipe its (empty) data dir, then reinstall fresh
    apt-get purge -y 'mysql-server*' 'mysql-client*' 'mysql-community*' mysql-common >/dev/null 2>&1
    apt-get autoremove -y >/dev/null 2>&1
    rm -rf /var/lib/mysql /var/log/mysql /etc/mysql
    dpkg --configure -a >/dev/null 2>&1
    apt-get update --allow-releaseinfo-change >/dev/null 2>&1
    return 0
}
export -f repair_mysql


install_pause() {
    local where="$1"
    echo ""
    echo -e "  ${C_WARN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${CR}"
    echo -e "  ${C_WARN}● Installation paused${CR} ${C_DIM}(${where})${CR}"
    echo -e "  ${C_DIM}This is usually caused by the server losing internet or a network error.${CR}"
    echo ""
    echo -e "  ${C_TXT}Completed steps are saved. Just run it again:${CR}"
    echo -e "      ${C_KEY}mirza install${CR}"
    echo -e "  ${C_DIM}It resumes from this step; values you already entered (domain/token/...) will not be asked again.${CR}"
    echo -e "  ${C_WARN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${CR}"
    echo ""
    exit 1
}

# Colored status dot
_dot() {
    case "$1" in
        ok)   printf "${C_OK}●${CR}"  ;;
        bad)  printf "${C_BAD}●${CR}" ;;
        warn) printf "${C_WARN}●${CR}";;
        *)    printf "${C_DIM}○${CR}" ;;
    esac
}
_sec() { printf "\n  ${C_ACCENT}▎${CR}${C_TITLE}%s${CR}\n" "$1"; _rule; }
_kv()  { printf "    ${C_LABEL}%-12s${CR} %b${CR}\n" "$1" "$2"; }

# Read the installed version from the source 'version' file
# $1 = bot directory (default: the first bot's)
get_installed_version() {
    local dir="${1:-$BOT_DIR_DEFAULT}"
    if [ -f "$dir/version" ]; then
        tr -d ' \t\r\n' < "$dir/version"
    else
        echo ""
    fi
}

# Choose which source to download.
# Sets globals: SRC_ZIP_URL, SRC_LABEL
# There is one source: the current code on master. Release tags used to be an
# option, and that is exactly what went wrong - the newest tag could sit well
# behind master, so "latest stable" quietly installed old code. No prompt now.
# Returns: 0 = chosen (it cannot fail)
choose_source() {
    SRC_ZIP_URL="https://github.com/${GIT_REPO}/archive/refs/heads/master.zip"
    SRC_LABEL="Latest"
    return 0
}

# Get public server IP, cached for 1 hour (falls back to local IP)
get_server_ip() {
    if [ -f "$IP_CACHE" ] && [ $(( $(date +%s) - $(stat -c %Y "$IP_CACHE" 2>/dev/null || echo 0) )) -lt 3600 ]; then
        cat "$IP_CACHE"
        return
    fi
    local ip
    ip=$(curl -fsSL --max-time 4 ifconfig.me 2>/dev/null)
    [ -z "$ip" ] && ip=$(curl -fsSL --max-time 4 https://api.ipify.org 2>/dev/null)
    [ -z "$ip" ] && ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    [ -z "$ip" ] && ip="n/a"
    echo "$ip" > "$IP_CACHE"
    echo "$ip"
}

# ── Dashboard sections ───────────────────────────────────────
# The three bot sections take the bot's directory; version_section also
# takes the block's title.
version_section() {
    local inst
    inst=$(get_installed_version "$1")
    _sec "${2:-Bot}"
    if [ -n "$inst" ]; then
        _kv "Version" "$(_dot ok) ${C_OK}${inst}${CR}"
    else
        _kv "Version" "$(_dot none) ${C_DIM}not installed${CR}"
    fi
}

bot_section() {
    local dir="${1:-$BOT_DIR_DEFAULT}"
    SSL_DOMAIN=""
    if [ ! -f "$dir/config.php" ]; then
        return
    fi
    SSL_DOMAIN=$(grep '^\$domainhosts' "$dir/config.php" | cut -d"'" -f2 | cut -d'/' -f1)
    if [ -n "$SSL_DOMAIN" ] && [ -f "/etc/letsencrypt/live/$SSL_DOMAIN/cert.pem" ]; then
        local expiry days
        expiry=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$SSL_DOMAIN/cert.pem" 2>/dev/null | cut -d= -f2)
        days=$(( ( $(date -d "$expiry" +%s 2>/dev/null || echo 0) - $(date +%s) ) / 86400 ))
        if [ "$days" -gt 14 ]; then
            _kv "Domain" "$(_dot ok) ${C_OK}${SSL_DOMAIN}${CR} ${C_DIM}· SSL ${days}d left${CR}"
        elif [ "$days" -gt 0 ]; then
            _kv "Domain" "$(_dot warn) ${C_WARN}${SSL_DOMAIN}${CR} ${C_DIM}· SSL ${days}d left, renew soon${CR}"
        else
            _kv "Domain" "$(_dot bad) ${C_BAD}${SSL_DOMAIN}${CR} ${C_BAD}· SSL expired${CR}"
        fi
    elif [ -n "$SSL_DOMAIN" ]; then
        _kv "Domain" "$(_dot warn) ${C_WARN}${SSL_DOMAIN}${CR} ${C_DIM}· no certificate${CR}"
    fi
    [ -n "$SSL_DOMAIN" ] && _kv "Database" "${C_DIM}$(pma_url "$SSL_DOMAIN" "$(bots_registry_pma_port "$dir")")${CR}"
}

# Read the Telegram webhook using the bot token from config.php.
# Prints webhook URL / pending count, and surfaces any error message.
webhook_section() {
    # No header of its own: this belongs under "Bot". One line when healthy,
    # detail only when something is actually wrong.
    local dir="${1:-$BOT_DIR_DEFAULT}"
    if [ ! -f "$dir/config.php" ]; then
        return
    fi
    local token info ok url pending err errdate apierr when host
    token=$(grep '^\$APIKEY' "$dir/config.php" | cut -d"'" -f2)
    if [ -z "$token" ]; then
        _kv "Webhook" "$(_dot bad) ${C_BAD}no token in config.php${CR}"
        return
    fi
    info=$(curl -fsSL --max-time 8 "https://api.telegram.org/bot${token}/getWebhookInfo" 2>/dev/null)
    if [ -z "$info" ]; then
        _kv "Webhook" "$(_dot warn) ${C_WARN}cannot reach Telegram${CR} ${C_DIM}· network or filtering${CR}"
        return
    fi
    if command -v jq >/dev/null 2>&1; then
        ok=$(echo "$info"     | jq -r '.ok')
        url=$(echo "$info"    | jq -r '.result.url // empty')
        pending=$(echo "$info"| jq -r '.result.pending_update_count // 0')
        err=$(echo "$info"    | jq -r '.result.last_error_message // empty')
        errdate=$(echo "$info"| jq -r '.result.last_error_date // empty')
        apierr=$(echo "$info" | jq -r '.description // empty')
    else
        ok=$(echo "$info"     | grep -oE '"ok":[[:space:]]*(true|false)' | grep -oE '(true|false)')
        url=$(echo "$info"    | grep -oE '"url":[[:space:]]*"[^"]*"' | sed -E 's/.*"url":[[:space:]]*"([^"]*)".*/\1/')
        pending=$(echo "$info"| grep -oE '"pending_update_count":[[:space:]]*[0-9]+' | grep -oE '[0-9]+$')
        err=$(echo "$info"    | grep -oE '"last_error_message":[[:space:]]*"[^"]*"' | sed -E 's/.*"last_error_message":[[:space:]]*"([^"]*)".*/\1/')
        errdate=$(echo "$info"| grep -oE '"last_error_date":[[:space:]]*[0-9]+' | grep -oE '[0-9]+$')
        apierr=$(echo "$info" | grep -oE '"description":[[:space:]]*"[^"]*"' | sed -E 's/.*"description":[[:space:]]*"([^"]*)".*/\1/')
        [ -z "$pending" ] && pending=0
    fi
    if [ "$ok" != "true" ]; then
        _kv "Webhook" "$(_dot bad) ${C_BAD}${apierr:-API error}${CR}"
        return
    fi
    if [ -z "$url" ]; then
        _kv "Webhook" "$(_dot bad) ${C_BAD}not set${CR} ${C_DIM}· Telegram has nowhere to deliver${CR}"
        return
    fi
    # Telegram keeps last_error_* after it recovers; with nothing pending the
    # error is history, not a current delivery problem.
    if [ -n "$err" ] && [ "${pending:-0}" -gt 0 ] 2>/dev/null; then
        when=""
        [ -n "$errdate" ] && when=$(date -d "@$errdate" '+%d %b %H:%M' 2>/dev/null)
        _kv "Webhook" "$(_dot warn) ${C_WARN}delivering with errors${CR} ${C_DIM}· ${pending} pending${CR}"
        _kv "" "${C_BAD}${err}${CR}${when:+ ${C_DIM}(${when})${CR}}"
    elif [ "${pending:-0}" -gt 50 ] 2>/dev/null; then
        _kv "Webhook" "$(_dot warn) ${C_WARN}${pending} updates queued${CR} ${C_DIM}· bot may be stalled${CR}"
    else
        _kv "Webhook" "$(_dot ok) ${C_OK}connected${CR} ${C_DIM}· ${pending} pending${CR}"
    fi
}

system_section() {
    local php_v apache_s mysql_s ip os apache_d mysql_d
    php_v=$(php -r 'echo PHP_VERSION;' 2>/dev/null); [ -z "$php_v" ] && php_v="n/a"
    apache_s=$(systemctl is-active apache2 2>/dev/null || echo "inactive")
    mysql_s=$(systemctl is-active mysql 2>/dev/null || echo "inactive")
    ip=$(get_server_ip)
    if [ -f /etc/os-release ]; then os=$(. /etc/os-release; echo "$PRETTY_NAME"); else os="Unknown"; fi
    if [ "$apache_s" = "active" ]; then apache_d="$(_dot ok) ${C_DIM}Apache${CR}"; else apache_d="$(_dot bad) ${C_BAD}Apache ${apache_s}${CR}"; fi
    if [ "$mysql_s" = "active" ];  then mysql_d="$(_dot ok) ${C_DIM}MySQL${CR}";   else mysql_d="$(_dot bad) ${C_BAD}MySQL ${mysql_s}${CR}"; fi
    _sec "Server"
    _kv "System" "${C_DIM}${os}${CR} ${C_BORDER}·${CR} ${C_DIM}PHP ${php_v}${CR}"
    _kv "Services" "${apache_d}  ${mysql_d}"
    _kv "Address" "${C_DIM}${ip}${CR}"
}

resources_section() {
    local mem_t mem_u mem_p disk_u disk_t disk_p load cores up bar
    mem_t=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}')
    mem_u=$(free -m 2>/dev/null | awk '/^Mem:/{print $3}')
    if [ -n "$mem_t" ] && [ "$mem_t" -gt 0 ] 2>/dev/null; then mem_p=$(( mem_u * 100 / mem_t )); else mem_p=0; fi
    disk_u=$(df -h / 2>/dev/null | awk 'NR==2{print $3}')
    disk_t=$(df -h / 2>/dev/null | awk 'NR==2{print $2}')
    disk_p=$(df -h / 2>/dev/null | awk 'NR==2{gsub(/%/,"",$5); print $5}')
    load=$(awk '{print $1" "$2" "$3}' /proc/loadavg 2>/dev/null)
    cores=$(nproc 2>/dev/null)
    up=$(uptime -p 2>/dev/null | sed 's/^up //; s/ hours\?/h/; s/ minutes\?/m/; s/ days\?/d/; s/,//g')
    [ -z "$up" ] && up="n/a"
    # a 10-cell meter, coloured by pressure
    _meter() {
        local pct="$1" filled i out="" col
        [ -z "$pct" ] && pct=0
        filled=$(( pct / 10 ))
        if   [ "$pct" -ge 90 ]; then col="$C_BAD"
        elif [ "$pct" -ge 70 ]; then col="$C_WARN"
        else col="$C_OK"; fi
        for ((i=0;i<10;i++)); do
            if [ "$i" -lt "$filled" ]; then out+="█"; else out+="░"; fi
        done
        printf "${col}%s${CR}" "$out"
    }
    _kv "Memory" "$(_meter "$mem_p") ${C_DIM}${mem_p}%  ${mem_u}/${mem_t} MB${CR}"
    _kv "Disk" "$(_meter "$disk_p") ${C_DIM}${disk_p}%  ${disk_u}/${disk_t}${CR}"
    local corelbl="cores"; [ "$cores" = "1" ] && corelbl="core"
    _kv "Load" "${C_DIM}${load}${CR} ${C_BORDER}·${CR} ${C_DIM}${cores} ${corelbl}${CR} ${C_BORDER}·${CR} ${C_DIM}up ${up}${CR}"
}

# One block per bot, numbered and named when there are two or more. A
# single bot (or a server whose registry does not exist yet) gets the one
# "Bot" block it always had. Read-only: never installs jq from here.
bots_dashboard() {
    local rows="" count dir name i=1
    if [ -f "$BOTS_REGISTRY" ] && command -v jq >/dev/null 2>&1; then
        rows=$(jq -r '.[] | "\(.dir)\t\(.name)"' "$BOTS_REGISTRY" 2>/dev/null)
    fi
    count=$(printf '%s\n' "$rows" | grep -c .)
    if [ "$count" -lt 2 ]; then
        dir="$BOT_DIR_DEFAULT"
        [ "$count" = "1" ] && dir=$(printf '%s' "$rows" | cut -f1)
        version_section "$dir"
        bot_section "$dir"
        webhook_section "$dir"
        return
    fi
    while IFS=$'\t' read -r dir name; do
        version_section "$dir" "Bot $i · $name"
        bot_section "$dir"
        webhook_section "$dir"
        i=$((i + 1))
    done <<< "$rows"
}

function show_logo() {
    clear 2>/dev/null || true
    banner
    bots_dashboard
    system_section
    resources_section
}

# Renew (or issue) the SSL certificate for the bot's domain.
function renew_ssl() {
    clear 2>/dev/null || true
    banner
    _sec "Renew SSL certificate"

    # 1) Detect the bot domain: the registry (name + domain, prompts only
    #    when more than one bot exists), then config.php, then saved state
    local domain=""
    if pick_bot_instance "renew the certificate for"; then
        domain="$PICKED_DOMAIN"
        [ "$domain" = "null" ] && domain=""
    fi
    if [ -z "$domain" ]; then
        local cfg="/var/www/html/mirzaprobotconfig/config.php"
        [ -f "$cfg" ] && domain=$(grep -E "\\\$domainhosts" "$cfg" 2>/dev/null | head -1 | cut -d"'" -f2)
    fi
    [ -z "$domain" ] && domain="$(state_get DOMAIN)"
    if [ -z "$domain" ]; then
        printf "  ${C_PROMPT}❯${CR} Enter the bot domain: "
        read -r domain
    fi
    if [ -z "$domain" ]; then
        echo -e "  ${C_BAD}●${CR} ${C_BAD}No domain found. Aborting.${CR}"
        sleep 1; show_menu; return 1
    fi
    _kv "Domain" "${C_KEY}${domain}${CR}"

    if ! command -v certbot >/dev/null 2>&1; then
        echo -e "  ${C_BAD}●${CR} ${C_BAD}certbot is not installed. Install Mirza first.${CR}"
        sleep 1; show_menu; return 1
    fi

    # Show current expiry, if a certificate already exists
    local certfile="/etc/letsencrypt/live/${domain}/cert.pem"
    if [ -f "$certfile" ]; then
        local exp
        exp=$(openssl x509 -enddate -noout -in "$certfile" 2>/dev/null | cut -d= -f2)
        [ -n "$exp" ] && _kv "Expires" "${C_DIM}${exp}${CR}"
    else
        echo -e "  ${C_WARN}!${CR} ${C_WARN}No existing certificate found - a new one will be issued.${CR}"
    fi
    echo ""

    # 2) Optional force (Let's Encrypt normally renews only within ~30 days of expiry)
    printf "  ${C_PROMPT}❯${CR} Force renewal now even if not near expiry? ${C_DIM}[y/N]${CR}: "
    read -r _force
    local force_flag=""
    [[ "$_force" =~ ^[Yy]$ ]] && force_flag="--force-renewal"
    echo ""

    # Use the apache authenticator so it works while Apache is running (no downtime).
    # certonly updates the existing cert lineage in place; Apache already points at it.
    run_step "Renewing certificate for ${domain}" \
        "certbot certonly --apache --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring --cert-name '${domain}' -d '${domain}' ${force_flag}" \
        || { show_step_error; echo -e "\n  ${C_BAD}●${CR} ${C_BAD}Renewal failed. See the details above.${CR}"; echo ""; printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "; read -r _; show_menu; return 1; }

    run_step "Reloading Apache" "systemctl reload apache2 2>/dev/null || systemctl restart apache2"

    _sec "Done"
    _kv "Domain" "${C_KEY}${domain}${CR}"
    if [ -f "$certfile" ]; then
        local newexp
        newexp=$(openssl x509 -enddate -noout -in "$certfile" 2>/dev/null | cut -d= -f2)
        [ -n "$newexp" ] && _kv "Valid until" "${C_OK}${newexp}${CR}"
    fi
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}

function backup_bot() {
    clear 2>/dev/null || true
    banner
    _sec "Backup Database"

    if ! pick_bot_instance "back up"; then
        echo ""
        printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
        read -r _
        show_menu
        return 1
    fi
    CONFIG_PATH="$PICKED_DIR/config.php"
    if [ ! -f "$CONFIG_PATH" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}Mirza is not installed. config.php not found.${CR}\n"
        echo ""
        printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
        read -r _
        show_menu
        return 1
    fi

    local dbhost dbname dbuser dbpass bot_token admin_id
    dbhost=$(grep '^\$dbhost' "$CONFIG_PATH" | cut -d"'" -f2)
    dbname=$(grep '^\$dbname' "$CONFIG_PATH" | cut -d"'" -f2)
    dbuser=$(grep '^\$usernamedb' "$CONFIG_PATH" | cut -d"'" -f2)
    dbpass=$(grep '^\$passworddb' "$CONFIG_PATH" | cut -d"'" -f2)
    bot_token=$(grep '^\$APIKEY' "$CONFIG_PATH" | cut -d"'" -f2)
    admin_id=$(grep '^\$adminnumber' "$CONFIG_PATH" | cut -d"'" -f2)
    [ -z "$dbhost" ] && dbhost="localhost"

    if [ -z "$dbname" ] || [ -z "$dbuser" ] || [ -z "$dbpass" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}Could not read database credentials from config.php${CR}\n"
        echo ""
        printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
        read -r _
        show_menu
        return 1
    fi

    _kv "Database" "${C_DIM}${dbname}${CR}"
    _kv "DB User" "${C_DIM}${dbuser}${CR}"
    _kv "DB Host" "${C_DIM}${dbhost}${CR}"
    echo ""

    local backup_date
    backup_date=$(date +"%Y-%m-%d_%H-%M-%S")
    local backup_file="/root/mirza_backup_${backup_date}.sql"

    run_step "Exporting database (${dbname})" \
        "mysqldump -h '$dbhost' -u '$dbuser' -p'$dbpass' --no-tablespaces --ssl-mode=DISABLED '$dbname' > '$backup_file'" \
        || { show_step_error; echo -e "\n  ${C_BAD}●${CR} ${C_BAD}Backup failed. See details above.${CR}"; echo ""; printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "; read -r _; show_menu; return 1; }

    local file_size
    file_size=$(du -h "$backup_file" 2>/dev/null | awk '{print $1}')
    _kv "File" "${C_OK}${backup_file}${CR}"
    _kv "Size" "${C_DIM}${file_size}${CR}"

    if [ -n "$bot_token" ] && [ -n "$admin_id" ]; then
        echo ""
        local send_result
        send_result=$(curl -s -o /dev/null -w "%{http_code}" \
            -F "chat_id=${admin_id}" \
            -F "document=@${backup_file}" \
            -F "caption=📦 Mirza DB Backup (${backup_date})" \
            "https://api.telegram.org/bot${bot_token}/sendDocument" 2>/dev/null)
        if [ "$send_result" = "200" ]; then
            _kv "Telegram" "$(_dot ok) ${C_OK}Backup sent to admin chat (${admin_id})${CR}"
        else
            _kv "Telegram" "$(_dot bad) ${C_BAD}Failed to send (HTTP ${send_result})${CR}"
            printf "    ${C_DIM}Make sure the bot token and admin chat ID are correct.${CR}\n"
        fi
    else
        echo ""
        printf "    ${C_WARN}!${CR} ${C_WARN}Bot token or admin ID not found in config - skipping Telegram send.${CR}\n"
    fi

    echo ""
    printf "    ${C_OK}✔${CR} ${C_OK}Backup saved to:${CR} ${C_KEY}${backup_file}${CR}\n"
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}

# ── Restore ───────────────────────────────────────────────────
_is_zip() { [ "$(head -c 4 "$1" 2>/dev/null | od -An -tx1 | tr -d ' \n')" = "504b0304" ]; }

# Does FILE look like an SQL dump? (a dump header or statements in its first MB)
_restore_is_sql() {
    head -c 1048576 "$1" 2>/dev/null \
        | grep -qaiE '^(-- MySQL dump|-- MariaDB dump|-- phpMyAdmin SQL Dump|CREATE TABLE|INSERT INTO|DROP TABLE)'
}

# The database a dump (on stdin) was taken from, from its mysqldump or
# phpMyAdmin header; empty when the header does not say.
_sql_source_db() {
    head -n 40 | grep -m1 -aoE 'Database: `?[A-Za-z0-9_]+' | sed -E 's/Database: `?//'
}

# The name of the bot on this server that uses DBNAME, or DBNAME itself
_db_owner_label() {
    local d n
    while IFS=$'\t' read -r d n; do
        if [ "$(grep '^\$dbname' "$d/config.php" 2>/dev/null | cut -d"'" -f2)" = "$1" ]; then
            echo "$n"; return
        fi
    done < <(jq -r '.[] | "\(.dir)\t\(.name)"' "$BOTS_REGISTRY" 2>/dev/null)
    echo "$1"
}

# One short note per listed file: whose data it is, or why it cannot be used
_restore_file_note() {
    local f="$1" entry src
    if _is_zip "$f"; then
        entry=$(unzip -Z1 "$f" 2>/dev/null | grep -iE '\.sql$' | head -1)
        if [ -z "$entry" ]; then echo "zip · no .sql inside"; return; fi
        if unzip -Z -v "$f" "$entry" 2>/dev/null | grep -q 'file security status:[[:space:]]*encrypted'; then
            echo "zip · password"; return
        fi
        src=$(unzip -p "$f" "$entry" 2>/dev/null | _sql_source_db)
    else
        src=$(_sql_source_db < "$f")
    fi
    [ -n "$src" ] && echo "from $(_db_owner_label "$src")"
}

# Turn the chosen file into an .sql to import: a plain dump is used as it
# is, a zip has its .sql extracted into WORK (asking for the password the
# bot's backup settings put on it, if any). Sets RESTORE_SQL and
# RESTORE_ENTRY. Returns 0 ready, 1 unusable (reason printed), 2 back.
_restore_prepare() {
    local f="$1" work="$2" entries n pick pw rc
    RESTORE_SQL=""; RESTORE_ENTRY=""
    if ! _is_zip "$f"; then
        if ! _restore_is_sql "$f"; then
            printf "    ${C_BAD}●${CR} ${C_BAD}This file is not a database backup (.sql or .zip).${CR}\n"
            return 1
        fi
        RESTORE_SQL="$f"; return 0
    fi
    entries=$(unzip -Z1 "$f" 2>/dev/null | grep -iE '\.sql$')
    if [ -z "$entries" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}This zip has no .sql file inside - it is not a database backup.${CR}\n"
        return 1
    fi
    n=$(printf '%s\n' "$entries" | wc -l)
    RESTORE_ENTRY=$(printf '%s\n' "$entries" | head -1)
    if [ "$n" -gt 1 ]; then
        printf "    ${C_DIM}This zip holds %d .sql files:${CR}\n" "$n"
        printf '%s\n' "$entries" | awk -v k="$C_KEY" -v t="$C_TXT" -v r="$CR" '{printf "    %s[%d]%s %s%s%s\n", k, NR, r, t, $0, r}'
        printf "  ${C_PROMPT}❯${CR} Which one? ${C_DIM}[1-%d, 0 = back]${CR}: " "$n"
        read -r pick
        [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -le "$n" ] || return 2
        RESTORE_ENTRY=$(printf '%s\n' "$entries" | sed -n "${pick}p")
    fi
    # A dummy password is ignored by an unprotected zip and simply fails on a
    # protected one - unzip never stops to ask on the terminal this way.
    unzip -p -P "mirza-no-password" "$f" "$RESTORE_ENTRY" > "$work/restore.sql" 2>/dev/null
    rc=$?
    if [ "$rc" -ne 0 ]; then
        printf "    ${C_DIM}This zip is password-protected (the password from the bot's backup settings).${CR}\n"
        while true; do
            printf "  ${C_PROMPT}❯${CR} Zip password ${C_DIM}[0 = back]${CR}: "
            read -rs pw; echo ""
            if [ -z "$pw" ] || [ "$pw" = "0" ]; then return 2; fi
            unzip -p -P "$pw" "$f" "$RESTORE_ENTRY" > "$work/restore.sql" 2>/dev/null && break
            printf "    ${C_BAD}●${CR} ${C_BAD}Wrong password, or the zip is damaged. Try again.${CR}\n"
        done
    fi
    if ! _restore_is_sql "$work/restore.sql"; then
        printf "    ${C_BAD}●${CR} ${C_BAD}%s inside the zip is not a database dump.${CR}\n" "$RESTORE_ENTRY"
        return 1
    fi
    RESTORE_SQL="$work/restore.sql"
    return 0
}

# DROP statements for everything now in the database, so a restore replaces
# it instead of piling onto it (phpMyAdmin exports carry no DROP TABLE)
_restore_drop_sql() {
    mysql -N -B -h "$1" -u "$2" -p"$3" "$4" -e 'SHOW FULL TABLES' 2>/dev/null \
        | while IFS=$'\t' read -r t type; do
            if [ "$type" = "VIEW" ]; then echo "DROP VIEW IF EXISTS \`$t\`;"
            else echo "DROP TABLE IF EXISTS \`$t\`;"; fi
        done
}

_restore_finish() {
    [ -n "$1" ] && rm -rf "$1"
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}

# Menu 7 - put a backup (.sql, or the .zip the bot sends) into one bot's
# database. The database is saved first; if the import fails half way it
# is put back from that copy, so a bad file never leaves the bot empty.
function import_bot() {
    clear 2>/dev/null || true
    banner
    _sec "Restore database"
    if ! pick_bot_instance "restore a backup into"; then
        [ "$(bots_registry_count)" = "0" ] && sleep 2
        show_menu; return 0
    fi
    local dir="$PICKED_DIR" name="$PICKED_NAME" cfg="$PICKED_DIR/config.php"
    if [ ! -f "$cfg" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}Mirza is not installed. config.php not found.${CR}\n"
        _restore_finish; return 1
    fi

    local dbhost dbname dbuser dbpass
    dbhost=$(grep '^\$dbhost' "$cfg" | cut -d"'" -f2)
    dbname=$(grep '^\$dbname' "$cfg" | cut -d"'" -f2)
    dbuser=$(grep '^\$usernamedb' "$cfg" | cut -d"'" -f2)
    dbpass=$(grep '^\$passworddb' "$cfg" | cut -d"'" -f2)
    [ -z "$dbhost" ] && dbhost="localhost"
    if [ -z "$dbname" ] || [ -z "$dbuser" ] || [ -z "$dbpass" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}Could not read database credentials from config.php${CR}\n"
        _restore_finish; return 1
    fi

    _kv "Bot" "${C_KEY}${name}${CR}"
    _kv "Database" "${C_DIM}${dbname}${CR}"
    echo ""
    printf "    ${C_DIM}Put the backup in /root - a .sql file, or the .zip the bot${CR}\n"
    printf "    ${C_DIM}sends you - and it shows up here (newest first).${CR}\n"
    echo ""
    local files=() f i=1
    while IFS= read -r f; do
        files+=("$f")
        printf "    ${C_KEY}[%d]${CR} ${C_TXT}%-38s${CR} ${C_DIM}%5s  %s  %s${CR}\n" "$i" "$(basename "$f")" \
            "$(du -h "$f" 2>/dev/null | cut -f1)" "$(date -r "$f" '+%d %b %H:%M' 2>/dev/null)" "$(_restore_file_note "$f")"
        i=$((i + 1))
    done < <(find /root -maxdepth 1 -type f \( -iname '*.sql' -o -iname '*.zip' \) -printf '%T@\t%p\n' 2>/dev/null \
                | sort -rn | head -n 15 | cut -f2-)
    [ "${#files[@]}" -eq 0 ] && printf "    ${C_WARN}!${CR} ${C_WARN}No .sql or .zip file in /root yet.${CR}\n"
    printf "    ${C_KEY}[0]${CR} ${C_TXT}Back to menu${CR}\n"
    echo ""
    printf "  ${C_PROMPT}❯${CR} Choose a file, or type its full path: "
    local choice file=""
    read -r choice
    if [ -z "$choice" ] || [ "$choice" = "0" ]; then show_menu; return 0; fi
    if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -le "${#files[@]}" ]; then
        file="${files[$((choice - 1))]}"
    else
        file="$choice"
    fi
    if [ ! -f "$file" ]; then
        printf "\n    ${C_BAD}●${CR} ${C_BAD}File not found: %s${CR}\n" "$file"
        _restore_finish; return 1
    fi

    local work; work=$(mktemp -d /root/.mirza_restore.XXXXXX)
    echo ""
    _restore_prepare "$file" "$work"
    case $? in
        0) ;;
        2) rm -rf "$work"; show_menu; return 0 ;;
        *) _restore_finish "$work"; return 1 ;;
    esac

    local src owner shown
    src=$(_sql_source_db < "$RESTORE_SQL")
    shown="$(basename "$file")"
    [ -n "$RESTORE_ENTRY" ] && shown="$shown → $RESTORE_ENTRY"
    _sec "Restore into ${name}"
    _kv "File" "${C_DIM}${shown}${CR} ${C_DIM}($(du -h "$RESTORE_SQL" 2>/dev/null | cut -f1))${CR}"
    if [ -n "$src" ]; then
        owner=$(_db_owner_label "$src")
        if [ "$owner" = "$src" ]; then _kv "Made from" "${C_DIM}${src}${CR}"
        else _kv "Made from" "${C_DIM}${owner} (${src})${CR}"; fi
    fi
    _kv "Into" "${C_KEY}${dbname}${CR} ${C_DIM}(${name})${CR}"
    echo ""
    if [ -n "$src" ] && [ "$src" != "$dbname" ]; then
        printf "    ${C_WARN}!${CR} ${C_WARN}This backup was made from another database (%s).${CR}\n" "$src"
    fi
    printf "    ${C_WARN}!${CR} ${C_WARN}Everything in %s is replaced. A copy of it is saved first.${CR}\n" "$dbname"
    printf "  ${C_PROMPT}❯${CR} Continue? ${C_DIM}[y/N]${CR}: "
    local confirm; read -r confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        printf "\n    ${C_DIM}Restore cancelled - nothing was changed.${CR}\n"
        _restore_finish "$work"; return 0
    fi
    echo ""

    local safe="/root/mirza_before_restore_${dbname}_$(date +%Y-%m-%d_%H-%M-%S).sql"
    local my="mysql -h '$dbhost' -u '$dbuser' -p'$dbpass' '$dbname'"
    if ! run_step "Saving the current database" \
        "mysqldump -h '$dbhost' -u '$dbuser' -p'$dbpass' --no-tablespaces --ssl-mode=DISABLED '$dbname' > '$safe'"; then
        show_step_error; rm -f "$safe"
        printf "\n    ${C_BAD}●${CR} ${C_BAD}Could not save the current database - nothing was changed.${CR}\n"
        _restore_finish "$work"; return 1
    fi
    _restore_drop_sql "$dbhost" "$dbuser" "$dbpass" "$dbname" > "$work/drop.sql"
    # A dump may name its own database (CREATE DATABASE / USE); drop those
    # lines so it always lands in this bot's database, never another one.
    if ! run_step "Importing $(basename "${RESTORE_ENTRY:-$file}")" \
        "{ echo 'SET FOREIGN_KEY_CHECKS=0;'; cat '$work/drop.sql'; sed -E '/^(CREATE DATABASE |USE \`)/d' '$RESTORE_SQL'; } | $my"; then
        show_step_error
        _restore_drop_sql "$dbhost" "$dbuser" "$dbpass" "$dbname" > "$work/drop.sql"
        if run_step "Putting the previous database back" \
            "{ echo 'SET FOREIGN_KEY_CHECKS=0;'; cat '$work/drop.sql'; cat '$safe'; } | $my"; then
            printf "\n    ${C_BAD}●${CR} ${C_BAD}The import failed, so the database was put back as it was.${CR}\n"
        else
            show_step_error
            printf "\n    ${C_BAD}●${CR} ${C_BAD}The import failed and the old data could not be put back.${CR}\n"
            printf "    ${C_DIM}It is saved in %s${CR}\n" "$safe"
        fi
        _restore_finish "$work"; return 1
    fi
    # An older backup gets the columns and tables this version expects
    run_step "Updating tables for this version" "cd '$dir' && php table.php" || show_step_error
    chown -R www-data:www-data "$dir" 2>/dev/null

    echo ""
    printf "    ${C_OK}✔${CR} ${C_OK}Restored into %s (%s).${CR}\n" "$dbname" "$name"
    printf "    ${C_DIM}The data it had before is kept in %s${CR}\n" "$safe"
    _restore_finish "$work"
}

function show_menu() {
    # A run with nobody at the keyboard (the bot's own 🔄 آپدیت ربات button,
    # carried out by the root watcher below) has no terminal: the `read` at the
    # bottom of this menu would hit EOF, fall into the "Not an option" branch
    # and call show_menu again - forever. Every error path in this script
    # returns to this menu, so guarding it in this one place is what makes all
    # 57 of them safe to reach unattended.
    if [ -n "$MIRZA_NONINTERACTIVE" ]; then
        return 0
    fi
    show_logo
    _sec "Menu"
    _mi "1" "Install"   "set up the bot on a clean server"
    _mi "2" "Update"    "newest code, keeps all your data"
    _mi "3" "Remove"    "delete the bot and its packages"
    _mi "4" "Migrate to Pro Max" "bring an original Mirza install over ${C_WARN}(beta)${CR}"
    _mi "5" "Renew SSL" "reissue the domain certificate"
    _mi "6" "Backup"    "database dump, sent to Telegram"
    _mi "7" "Restore"   "import a .sql or .zip backup ${C_WARN}(beta)${CR}"
    _mi "8" "Help"      "commands and flags for scripts"
    _mi "9" "Database"  "password, login info, phpMyAdmin port"
    _mi "0" "Exit"      ""
    _rule
    echo ""
    printf  "  ${C_PROMPT}❯${CR} Choose ${C_DIM}[0-9]${CR}: "
    read -r option
    case $option in
        1) install_bot ;;
        2) update_bot ;;
        3) remove_bot ;;
        4) migrate_to_pro ;;
        5) renew_ssl ;;
        6) backup_bot ;;
        7) import_bot ;;
        8) show_help_screen ;;
        9) database_menu ;;
        0) echo -e "\n${C_OK}Bye.${CR}"; exit 0 ;;
        *) echo -e "\n${C_BAD}Not an option. Try again.${CR}"; sleep 1; show_menu ;;
    esac
}

# Clean, styled guide of all commands and parameters
function show_help_screen() {
    clear 2>/dev/null || true
    banner

    _sec "Commands"
    _kv "install" "${C_DIM}Install Mirza${CR}"
    _kv "update" "${C_DIM}Update Mirza to the latest code${CR}"
    _kv "remove" "${C_DIM}Remove Mirza and its services${CR}"
    _kv "migrate" "${C_DIM}Migrate an original Mirza to Pro Max${CR}"
    _kv "addbot" "${C_DIM}Install a second, independent bot on this server${CR}"
    _kv "renew" "${C_DIM}Renew the bot domain SSL certificate${CR}"
    _kv "backup" "${C_DIM}Backup database & send to Telegram${CR}"
    _kv "import" "${C_DIM}Import a database backup, .sql or .zip (Beta)${CR}"
    _kv "menu" "${C_DIM}Open this interactive panel (default)${CR}"

    _sec "Install parameters"
    _kv "--name" "${C_DIM}Bot username${CR}"
    _kv "--token" "${C_DIM}Telegram bot token${CR}"
    _kv "--admin" "${C_DIM}Admin chat id${CR}"
    _kv "--domain" "${C_DIM}Domain name (e.g. bot.example.com)${CR}"
    _kv "--db-user" "${C_DIM}Database username${CR}"
    _kv "--db-pass" "${C_DIM}Database password${CR}"

    _kv "-h, --help" "${C_DIM}Show CLI help and exit${CR}"

    _sec "Examples"
    printf "    ${C_KEY}mirza install${CR}\n"
    printf "    ${C_KEY}mirza install --name myvpnbot --token 123:ABC \\\\${CR}\n"
    printf "    ${C_DIM}            --admin 111 --domain bot.example.com${CR}\n"
    printf "    ${C_KEY}mirza update${CR}\n"
    printf "    ${C_KEY}mirza remove${CR}\n"
    printf "    ${C_KEY}mirza backup${CR}\n"
    printf "    ${C_KEY}mirza import${CR}\n"

    echo ""
    _rule
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}
function find_free_port() {
    for port in {3300..3330}; do
        if ! ss -tuln | grep -q ":$port "; then
            echo "$port"
            return 0
        fi
    done
    echo -e "\033[31m[ERROR] No free port found between 3300 and 3330.\033[0m"
    exit 1
}
function fix_update_issues() {
    echo -e "\e[33mTrying to fix update issues by changing mirrors...\033[0m"
    # Broken apt mirrors are often a DNS problem - fix DNS first
    ensure_dns
    if ! detect_os || [ -z "$OS_CODENAME" ]; then
        echo -e "\e[91mCould not detect Ubuntu version.\033[0m"
        return 1
    fi

    # Ubuntu 24.04+ (and 26.04) ship the deb822 file and often have no
    # /etc/apt/sources.list at all - rewrite whichever one this release uses.
    local DEB822=/etc/apt/sources.list.d/ubuntu.sources
    local LEGACY=/etc/apt/sources.list
    local target="" fmt=""
    if [ -f "$DEB822" ]; then target="$DEB822"; fmt="deb822"
    else target="$LEGACY"; fmt="legacy"; fi
    [ -f "$target" ] && cp "$target" "$target.mirzabackup"

    local parked=""
    if [ "$fmt" = "deb822" ] && [ -s "$LEGACY" ]; then
        cp "$LEGACY" "$LEGACY.mirzabackup" && : > "$LEGACY" && parked="$LEGACY"
    fi

    # arm64/armhf live on ports.ubuntu.com, not the archive mirrors.
    local arch path MIRRORS
    arch=$(dpkg --print-architecture 2>/dev/null || uname -m)
    case "$arch" in
        arm64|armhf|ppc64el|s390x|riscv64)
            MIRRORS=("ports.ubuntu.com")
            path="ubuntu-ports"
            ;;
        *)
            MIRRORS=(
                "archive.ubuntu.com"
                "us.archive.ubuntu.com"
                "fr.archive.ubuntu.com"
                "de.archive.ubuntu.com"
                "mirrors.digitalocean.com"
                "mirrors.linode.com"
            )
            path="ubuntu"
            ;;
    esac

    local mirror
    for mirror in "${MIRRORS[@]}"; do
        echo -e "\e[33mTrying mirror: $mirror\033[0m"
        if [ "$fmt" = "deb822" ]; then
            cat > "$target" << EOF
Types: deb
URIs: http://$mirror/$path/
Suites: $OS_CODENAME $OS_CODENAME-updates $OS_CODENAME-backports $OS_CODENAME-security
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
EOF
        else
            cat > "$target" << EOF
deb http://$mirror/$path/ $OS_CODENAME main restricted universe multiverse
deb http://$mirror/$path/ $OS_CODENAME-updates main restricted universe multiverse
deb http://$mirror/$path/ $OS_CODENAME-security main restricted universe multiverse
EOF
        fi
        if apt-get update --allow-releaseinfo-change 2>/dev/null; then
            echo -e "\e[32mSuccessfully updated using mirror: $mirror\033[0m"
            rm -f "$target.mirzabackup"
            [ -n "$parked" ] && rm -f "$parked.mirzabackup"
            return 0
        fi
    done
    if [ -f "$target.mirzabackup" ]; then
        mv "$target.mirzabackup" "$target"
    else
        rm -f "$target"
    fi
    [ -n "$parked" ] && [ -f "$parked.mirzabackup" ] && mv "$parked.mirzabackup" "$parked"
    echo -e "\e[91mAll mirrors failed. Restored original apt sources\033[0m"
    return 1
}

# ─────────────────────────────────────────────────────────────
#  Validation and pre-flight checks
#  (DNS helpers dns_works/ensure_dns are defined near the top)
# ─────────────────────────────────────────────────────────────

# Can we actually reach the internet?
net_works() {
    curl -fsSL --max-time 8 -o /dev/null "https://github.com" 2>/dev/null && return 0
    curl -fsSL --max-time 8 -o /dev/null "https://api.telegram.org" 2>/dev/null && return 0
    return 1
}

# Ensure DNS + connectivity, fixing DNS automatically if needed.
ensure_connectivity() {
    ensure_dns
    net_works && return 0
    echo -e "  ${C_WARN}!${CR} ${C_WARN}No connectivity - resetting DNS and retrying...${CR}"
    ensure_dns
    net_works && return 0
    return 1
}

# ── Input validators ─────────────────────────────────────────
validate_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }

# 0 = points here, 1 = points elsewhere, 2 = could not resolve
domain_points_here() {
    local dom="$1" myip resolved
    myip=$(get_server_ip)
    resolved=$(getent ahostsv4 "$dom" 2>/dev/null | awk '{print $1; exit}')
    [ -z "$resolved" ] && resolved=$(getent hosts "$dom" 2>/dev/null | awk '{print $1; exit}')
    [ -z "$resolved" ] && return 2
    [ "$resolved" = "$myip" ] && return 0
    return 1
}

# 0 = valid+live, 1 = bad format, 2 = format ok but token rejected/unreachable
validate_token() {
    [[ "$1" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]] || return 1
    local r; r=$(curl -fsSL --max-time 8 "https://api.telegram.org/bot$1/getMe" 2>/dev/null)
    echo "$r" | grep -q '"ok":true' && return 0
    return 2
}

# Safe identifiers/passwords (no quotes/specials that break SQL or config.php)
valid_db_ident() { [[ "$1" =~ ^[A-Za-z0-9_]{1,32}$ ]]; }
valid_db_pass()  { [[ "$1" =~ ^[A-Za-z0-9_]{6,64}$ ]]; }

# ── Multi-bot registry ────────────────────────────────────────
# Tracks every bot installed on this server (name, directory, domain) so
# Update/Remove/Renew SSL/Backup/Restore can ask which one to act on. A
# server that only ever had the one bot never sees any of this - the
# registry silently carries a single entry and every picker below returns
# it without a prompt, exactly like before this existed.
BOTS_REGISTRY="/root/confmirza/bots.json"

_ensure_jq() {
    command -v jq >/dev/null 2>&1 || apt-get install -y jq >/dev/null 2>&1
}

# Make sure the registry file exists and, for a server that predates it,
# seed it from whatever is already installed at the default path.
bots_registry_ensure() {
    _ensure_jq
    mkdir -p /root/confmirza
    [ -f "$BOTS_REGISTRY" ] || echo '[]' > "$BOTS_REGISTRY"
    if [ "$(jq 'length' "$BOTS_REGISTRY" 2>/dev/null)" = "0" ] \
        && [ -f "$BOT_DIR_DEFAULT/config.php" ]; then
        local name domain
        name=$(grep '^\$usernamebot' "$BOT_DIR_DEFAULT/config.php" 2>/dev/null | cut -d"'" -f2)
        [ -z "$name" ] && name="Bot 1"
        domain=$(grep '^\$domainhosts' "$BOT_DIR_DEFAULT/config.php" 2>/dev/null | cut -d"'" -f2 | cut -d'/' -f1)
        bots_registry_add "$name" "$BOT_DIR_DEFAULT" "$domain"
    fi
}

# bots_registry_add NAME DIR DOMAIN - insert, or update if DIR is already
# registered (so re-running install on the same directory does not duplicate).
# An update keeps the entry's other fields (pma_port).
bots_registry_add() {
    local name="$1" dir="$2" domain="$3"
    _ensure_jq
    mkdir -p /root/confmirza
    [ -f "$BOTS_REGISTRY" ] || echo '[]' > "$BOTS_REGISTRY"
    local tmp; tmp=$(mktemp)
    jq --arg name "$name" --arg dir "$dir" --arg domain "$domain" '
        (map(.dir) | index($dir)) as $i
        | if $i != null then .[$i] += {name:$name,dir:$dir,domain:$domain}
          else . + [{name:$name,dir:$dir,domain:$domain}]
          end
    ' "$BOTS_REGISTRY" > "$tmp" && mv "$tmp" "$BOTS_REGISTRY"
}

bots_registry_remove_by_dir() {
    local dir="$1"
    _ensure_jq
    [ -f "$BOTS_REGISTRY" ] || return 0
    local tmp; tmp=$(mktemp)
    jq --arg dir "$dir" '[.[] | select(.dir != $dir)]' "$BOTS_REGISTRY" > "$tmp" && mv "$tmp" "$BOTS_REGISTRY"
}

bots_registry_count() {
    bots_registry_ensure
    jq 'length' "$BOTS_REGISTRY" 2>/dev/null || echo 0
}

# Sets PICKED_NAME / PICKED_DIR / PICKED_DOMAIN. Prompts only when more than
# one bot is registered; a single bot (or a legacy server with none
# registered yet) is picked automatically, no prompt, same as before.
# $1 = a short label for the prompt, e.g. "update".
# Returns 1 if there is nothing to act on, or the user backs out.
pick_bot_instance() {
    local action="${1:-manage}"
    bots_registry_ensure
    # The watcher already knows which install asked for this update (the request
    # file was found in that very directory), so it says so outright. Without
    # this, a server with two bots would reach the "which bot?" prompt with no
    # one there to answer it.
    if [ -n "$MIRZA_BOT_DIR" ] && [ -d "$MIRZA_BOT_DIR" ]; then
        PICKED_DIR="$MIRZA_BOT_DIR"
        PICKED_NAME=$(jq -r --arg d "$MIRZA_BOT_DIR" '.[] | select(.dir==$d) | .name' "$BOTS_REGISTRY" 2>/dev/null | head -1)
        PICKED_DOMAIN=$(jq -r --arg d "$MIRZA_BOT_DIR" '.[] | select(.dir==$d) | .domain' "$BOTS_REGISTRY" 2>/dev/null | head -1)
        [ -z "$PICKED_NAME" ] && PICKED_NAME="Bot"
        return 0
    fi
    local count; count=$(jq 'length' "$BOTS_REGISTRY" 2>/dev/null)
    if [ -z "$count" ] || [ "$count" = "0" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}No Mirza bot found on this server.${CR}\n"
        return 1
    fi
    if [ "$count" = "1" ]; then
        PICKED_NAME=$(jq -r '.[0].name' "$BOTS_REGISTRY")
        PICKED_DIR=$(jq -r '.[0].dir' "$BOTS_REGISTRY")
        PICKED_DOMAIN=$(jq -r '.[0].domain' "$BOTS_REGISTRY")
        return 0
    fi
    _sec "Which bot do you want to $action?"
    local i=1
    while IFS=$'\t' read -r name domain; do
        printf "    ${C_KEY}[%d]${CR} ${C_TXT}%s${CR}  ${C_DIM}%s${CR}\n" "$i" "$name" "$domain"
        i=$((i + 1))
    done < <(jq -r '.[] | "\(.name)\t\(.domain)"' "$BOTS_REGISTRY")
    printf "    ${C_KEY}[0]${CR} ${C_TXT}Back to menu${CR}\n"
    echo ""
    printf "  ${C_PROMPT}❯${CR} Choose ${C_DIM}[0-%d]${CR}: " "$((count))"
    local pick; read -r pick
    if ! [[ "$pick" =~ ^[0-9]+$ ]] || [ "$pick" -lt 1 ] || [ "$pick" -gt "$count" ]; then
        return 1
    fi
    local idx=$((pick - 1))
    PICKED_NAME=$(jq -r ".[$idx].name" "$BOTS_REGISTRY")
    PICKED_DIR=$(jq -r ".[$idx].dir" "$BOTS_REGISTRY")
    PICKED_DOMAIN=$(jq -r ".[$idx].domain" "$BOTS_REGISTRY")
    return 0
}

# ── phpMyAdmin port ───────────────────────────────────────────
# phpMyAdmin is included in each bot's own vhosts, so it answers at
# https://<bot domain>/phpmyadmin - on 443, next to the webhook. A bot can
# move it to a port of its own instead: https://<bot domain>:<port>/phpmyadmin.
# The webhook never moves (Telegram only calls 443). Several bots may share
# one port: Apache tells them apart by domain (SNI), exactly as on 443.
# The registry's pma_port is the source of truth; pma_sync_all turns it
# into Apache config.
PMA_CONF="/etc/apache2/conf-available/phpmyadmin.conf"
PMA_PORTS_CONF="/etc/apache2/conf-available/mirza-pma-ports.conf"

# bots_registry_pma_port DIR - that bot's phpMyAdmin port (443 when unset)
bots_registry_pma_port() {
    local p
    p=$(jq -r --arg d "$1" '.[] | select(.dir==$d) | .pma_port // empty' "$BOTS_REGISTRY" 2>/dev/null | head -1)
    echo "${p:-443}"
}

bots_registry_set_pma_port() {
    local tmp; tmp=$(mktemp)
    jq --arg d "$1" --arg p "$2" 'map(if .dir==$d then .pma_port=($p|tonumber) else . end)' \
        "$BOTS_REGISTRY" > "$tmp" && mv "$tmp" "$BOTS_REGISTRY"
}

# pma_url DOMAIN PORT [PROTO]
pma_url() {
    if [ "$2" = "443" ]; then
        echo "${3:-https}://$1/phpmyadmin"
    else
        echo "https://$1:$2/phpmyadmin"
    fi
}

# Prints why PORT cannot carry phpMyAdmin and returns 1; silent when it can.
pma_port_problem() {
    local p="$1" held who
    if ! [[ "$p" =~ ^[1-9][0-9]{0,4}$ ]]; then echo "Enter a port number."; return 1; fi
    [ "$p" -eq 443 ] && return 0
    if [ "$p" -lt 1024 ] || [ "$p" -gt 65535 ]; then
        echo "Use 443, or a port from 1024 to 65535."; return 1
    fi
    held=$(ss -ltnp "( sport = :$p )" 2>/dev/null | tail -n +2)
    [ -z "$held" ] && return 0
    who=$(printf '%s' "$held" | grep -o 'users:(("[^"]*"' | head -1 | cut -d'"' -f2)
    # Apache already on it for another bot's phpMyAdmin: sharing is fine
    if [ "$who" = "apache2" ] \
        && jq -e --arg p "$p" 'any(.[]; .pma_port == ($p|tonumber))' "$BOTS_REGISTRY" >/dev/null 2>&1; then
        return 0
    fi
    echo "Port $p is already used by ${who:-another program}. Pick another one."
    return 1
}

# Asked while a bot is being set up; Enter keeps 443. Sets PMA_PORT.
ask_pma_port() {
    local p why
    while true; do
        printf "\e[33m[+] \e[36mphpMyAdmin port ${CR}${C_DIM}[default: 443]${CR}: "
        read -r p
        [ -z "$p" ] && p=443
        if why=$(pma_port_problem "$p"); then break; fi
        echo -e "\e[91m${why}\033[0m"
    done
    PMA_PORT="$p"
}

_pma_restore() {
    local d
    for d in sites-available sites-enabled conf-available conf-enabled; do
        rm -rf "/etc/apache2/$d" && cp -a "$1/$d" /etc/apache2/
    done
}

# Write every bot's phpMyAdmin wiring from the registry, then reload Apache.
# A bot on 443 keeps phpMyAdmin inside its own two vhosts, as the installer
# writes them; a bot on another port has it taken out of those and served
# from <domain>-pma.conf instead, on that port with the bot's certificate.
# Apache's config is backed up first and put back if Apache rejects the
# result, so a bad port can never take the webhooks down with it.
pma_sync_all() {
    bots_registry_ensure
    local sa="/etc/apache2/sites-available" rows domain port ports="" f p
    rows=$(jq -r '.[] | select(.domain != null and .domain != "" and .domain != "null") | "\(.domain) \(.pma_port // 443)"' "$BOTS_REGISTRY" 2>/dev/null)
    # Nothing ever moved: leave Apache exactly as the installer wrote it
    if [ -z "$(printf '%s\n' "$rows" | awk '$2 != "" && $2 != 443')" ] \
        && [ ! -f "$PMA_PORTS_CONF" ] && ! ls "$sa"/*-pma.conf >/dev/null 2>&1; then
        return 0
    fi
    local bak; bak=$(mktemp -d)
    cp -a /etc/apache2/sites-available /etc/apache2/sites-enabled \
          /etc/apache2/conf-available /etc/apache2/conf-enabled "$bak"/

    while read -r domain port; do
        [ -z "$domain" ] && continue
        if [ "$port" != "443" ] && [ ! -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then
            printf "    ${C_WARN}!${CR} ${C_WARN}%s has no SSL certificate - its phpMyAdmin stays on 443.${CR}\n" "$domain"
            port=443
        fi
        if [ "$port" = "443" ]; then
            for f in "$sa/${domain}.conf" "$sa/${domain}-ssl.conf"; do
                [ -f "$f" ] || continue
                grep -qF "Include $PMA_CONF" "$f" && continue
                awk -v inc="    Include $PMA_CONF" '/<\/VirtualHost>/{print inc} {print}' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
            done
            if [ -f "$sa/${domain}-pma.conf" ]; then
                a2dissite "${domain}-pma.conf" >/dev/null 2>&1 </dev/null
                rm -f "$sa/${domain}-pma.conf"
            fi
        else
            for f in "$sa/${domain}.conf" "$sa/${domain}-ssl.conf"; do
                [ -f "$f" ] && sed -i '/^[[:space:]]*Include[[:space:]].*phpmyadmin\.conf/d' "$f"
            done
            tee "$sa/${domain}-pma.conf" > /dev/null <<EOF
<VirtualHost *:${port}>
    ServerName $domain
    DocumentRoot /usr/share/phpmyadmin
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$domain/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$domain/privkey.pem
    Include $PMA_CONF
    ErrorLog \${APACHE_LOG_DIR}/${domain}-error.log
    CustomLog \${APACHE_LOG_DIR}/${domain}-access.log combined
</VirtualHost>
EOF
            a2ensite "${domain}-pma.conf" >/dev/null 2>&1 </dev/null
            ports="$ports $port"
        fi
    done <<< "$rows"

    ports=$(printf '%s\n' $ports | sort -un | tr '\n' ' ')
    if [ -n "${ports// /}" ]; then
        {
            echo "# Written by the mirza script: ports that carry a bot's phpMyAdmin."
            for p in $ports; do echo "Listen $p https"; done
        } > "$PMA_PORTS_CONF"
        a2enconf mirza-pma-ports >/dev/null 2>&1
        # The phpMyAdmin package enables /phpmyadmin for every site on its
        # own, which would keep it on 443 for a bot that just moved away.
        # Bots still on 443 carry their own Include (made sure of above).
        a2disconf phpmyadmin >/dev/null 2>&1
    else
        a2disconf mirza-pma-ports >/dev/null 2>&1
        rm -f "$PMA_PORTS_CONF"
    fi

    local out
    if ! out=$(apache2ctl configtest 2>&1); then
        _pma_restore "$bak"; rm -rf "$bak"
        printf "    ${C_BAD}●${CR} ${C_BAD}Apache rejected the new config - it was put back as it was.${CR}\n"
        printf '%s\n' "$out" | tail -n 5 | sed 's/^/      /'
        return 1
    fi
    systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1
    sleep 1
    # A reload does not always open a brand-new Listen port; a restart does
    for p in $ports; do
        if [ -z "$(ss -ltn "( sport = :$p )" 2>/dev/null | tail -n +2)" ]; then
            systemctl restart apache2 >/dev/null 2>&1; sleep 1; break
        fi
    done
    if ! systemctl is-active --quiet apache2; then
        _pma_restore "$bak"; rm -rf "$bak"
        systemctl restart apache2 >/dev/null 2>&1
        printf "    ${C_BAD}●${CR} ${C_BAD}Apache would not start with the new port - the old setup is back.${CR}\n"
        return 1
    fi
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        for p in $ports; do ufw allow "$p/tcp" >/dev/null 2>&1; done
    fi
    rm -rf "$bak"
    return 0
}

# pma_set_port DIR PORT - store it and apply it; on failure the old port
# is stored again (pma_sync_all has already put Apache back).
pma_set_port() {
    local dir="$1" port="$2" old
    old=$(bots_registry_pma_port "$dir")
    bots_registry_set_pma_port "$dir" "$port"
    pma_sync_all && return 0
    bots_registry_set_pma_port "$dir" "$old"
    return 1
}

_back_prompt() {
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to go back... "
    read -r _
}

# ALTER USER statements giving USER the password PASS on every host MySQL
# has it on ($3 = "host<TAB>plugin" rows), each keeping its own auth plugin.
_db_alter_sql() {
    local user="$1" pass="$2" h plugin sql=""
    while IFS=$'\t' read -r h plugin; do
        [[ "$h" =~ ^[A-Za-z0-9._%:-]+$ ]] && [[ "$plugin" =~ ^[a-z0-9_]+$ ]] || continue
        sql+="ALTER USER '${user}'@'${h}' IDENTIFIED WITH ${plugin} BY '${pass}'; "
    done <<< "$3"
    echo "$sql"
}

# db_set_password CONFIG NEWPASS - a new password for the bot's MySQL user,
# in MySQL and in config.php together. If config.php cannot take it, or the
# bot cannot log in with it, MySQL gets the old one back and config.php is
# restored, so the bot is never left holding a password that fails.
# Prints the reason and returns 1 on failure.
db_set_password() {
    local cfg="$1" new="$2" dbuser dbname dbhost old rootpass rows sql bak err
    dbuser=$(grep '^\$usernamedb' "$cfg" | cut -d"'" -f2)
    dbname=$(grep '^\$dbname' "$cfg" | cut -d"'" -f2)
    dbhost=$(grep '^\$dbhost' "$cfg" | cut -d"'" -f2)
    old=$(grep '^\$passworddb' "$cfg" | cut -d"'" -f2)
    rootpass=$(grep '$pass' /root/confmirza/dbrootmirza.txt 2>/dev/null | cut -d"'" -f2)
    [ -z "$dbhost" ] && dbhost="localhost"
    if ! valid_db_ident "$dbuser"; then echo "config.php has no usable database username."; return 1; fi
    if [ -z "$rootpass" ]; then echo "Could not read the MySQL root password."; return 1; fi
    rows=$(mysql -u root -p"$rootpass" -N -B -e "SELECT host, plugin FROM mysql.user WHERE user='${dbuser}';" 2>/dev/null)
    sql=$(_db_alter_sql "$dbuser" "$new" "$rows")
    if [ -z "$sql" ]; then echo "MySQL has no user named ${dbuser} (or the root login failed)."; return 1; fi
    if ! err=$(mysql -u root -p"$rootpass" -e "$sql" 2>&1); then
        echo "MySQL refused the change: $(printf '%s\n' "$err" | grep -v 'password on the command line' | tail -n 1)"
        return 1
    fi
    bak=$(mktemp); cp -p "$cfg" "$bak"
    sed -i -E "s/^(\\\$passworddb[[:space:]]*=[[:space:]]*')[^']*'/\\1${new}'/" "$cfg"
    if [ "$(grep '^\$passworddb' "$cfg" | cut -d"'" -f2)" != "$new" ] \
        || ! mysql -h "$dbhost" -u "$dbuser" -p"$new" -e "SELECT 1;" "$dbname" >/dev/null 2>&1; then
        cat "$bak" > "$cfg"; rm -f "$bak"
        [ -n "$old" ] && mysql -u root -p"$rootpass" -e "$(_db_alter_sql "$dbuser" "$old" "$rows")" >/dev/null 2>&1
        echo "The bot could not log in with the new password, so the old one was put back."
        return 1
    fi
    rm -f "$bak"
    # mod_php may still hold the old config.php in its opcode cache
    systemctl reload apache2 >/dev/null 2>&1
    return 0
}

db_show_password() {
    echo ""
    _kv "Password" "${C_KEY}$(grep '^\$passworddb' "$1" | cut -d"'" -f2)${CR}"
    printf "    ${C_DIM}Only root can open this script, and config.php already holds this${CR}\n"
    printf "    ${C_DIM}password - still, keep it out of screenshots and shared screens.${CR}\n"
    _back_prompt
}

db_change_password() {
    local cfg="$1" new out
    echo ""
    printf "    ${C_DIM}6 to 64 characters: letters, digits and _ only.${CR}\n"
    while true; do
        printf "  ${C_PROMPT}❯${CR} New password ${C_DIM}[Enter = random, 0 = back]${CR}: "
        read -r new
        [ "$new" = "0" ] && return 0
        [ -z "$new" ] && new=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | cut -c1-16)
        valid_db_pass "$new" && break
        printf "    ${C_BAD}●${CR} ${C_BAD}Use 6 to 64 characters: letters, digits and _ only.${CR}\n"
    done
    echo ""
    if out=$(db_set_password "$cfg" "$new"); then
        _kv "Password" "${C_OK}${new}${CR}"
        printf "    ${C_OK}●${CR} ${C_OK}Changed in MySQL and in config.php - the bot keeps working.${CR}\n"
        printf "    ${C_DIM}Use it to log in to phpMyAdmin from now on. Save it somewhere safe.${CR}\n"
    else
        printf "    ${C_BAD}●${CR} ${C_BAD}%s${CR}\n" "$out"
        printf "    ${C_DIM}The password was not changed.${CR}\n"
    fi
    _back_prompt
}

# Move this bot's phpMyAdmin to another port, or back to 443.
pma_port_screen() {
    local dir="$1" domain="$2" cur p why
    echo ""
    if [ -z "$domain" ] || [ "$domain" = "null" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}No domain is on record for this bot.${CR}\n"
        _back_prompt; return 1
    fi
    cur=$(bots_registry_pma_port "$dir")
    printf "    ${C_DIM}443 is the default (the bot's own address). The webhook stays on${CR}\n"
    printf "    ${C_DIM}443 whatever you pick, and several bots may share one port.${CR}\n"
    echo ""
    while true; do
        printf "  ${C_PROMPT}❯${CR} New port ${C_DIM}[now %s, 0 = back]${CR}: " "$cur"
        read -r p
        if [ -z "$p" ] || [ "$p" = "0" ]; then return 0; fi
        if ! why=$(pma_port_problem "$p"); then
            printf "    ${C_BAD}●${CR} ${C_BAD}%s${CR}\n" "$why"; continue
        fi
        if [ "$p" != "443" ] && [ ! -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then
            printf "    ${C_BAD}●${CR} ${C_BAD}%s has no SSL certificate yet - renew it first (menu 5).${CR}\n" "$domain"; continue
        fi
        break
    done
    echo ""
    if [ "$p" = "$cur" ]; then
        printf "    ${C_DIM}Already on port %s - nothing to change.${CR}\n" "$p"
    elif pma_set_port "$dir" "$p"; then
        printf "    ${C_OK}●${CR} ${C_OK}phpMyAdmin is now at %s${CR}\n" "$(pma_url "$domain" "$p")"
        [ "$p" != "443" ] && printf "    ${C_DIM}If your server provider has its own firewall, open port %s there too.${CR}\n" "$p"
    else
        printf "    ${C_BAD}●${CR} ${C_BAD}Port %s could not be used - phpMyAdmin is still at %s${CR}\n" "$p" "$(pma_url "$domain" "$cur")"
    fi
    _back_prompt
}

# Menu 9 - one bot's database: its login, its password, its phpMyAdmin port.
function database_menu() {
    clear 2>/dev/null || true
    banner
    _sec "Database"
    if ! pick_bot_instance "manage the database of"; then
        [ "$(bots_registry_count)" = "0" ] && sleep 2
        show_menu; return 0
    fi
    local dir="$PICKED_DIR" name="$PICKED_NAME" domain="$PICKED_DOMAIN" cfg="$PICKED_DIR/config.php" choice
    if [ ! -f "$cfg" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}config.php not found in %s.${CR}\n" "$dir"
        sleep 2; show_menu; return 1
    fi
    while true; do
        clear 2>/dev/null || true
        banner
        _sec "Database · ${name}"
        _kv "phpMyAdmin" "${C_DIM}$(pma_url "$domain" "$(bots_registry_pma_port "$dir")")${CR}"
        _kv "Database" "${C_KEY}$(grep '^\$dbname' "$cfg" | cut -d"'" -f2)${CR}"
        _kv "Username" "${C_KEY}$(grep '^\$usernamedb' "$cfg" | cut -d"'" -f2)${CR}"
        _kv "Password" "${C_DIM}hidden · option 1 shows it${CR}"
        echo ""
        _mi "1" "Show password"
        _mi "2" "Change phpMyAdmin password"
        _mi "3" "Change phpMyAdmin port"
        _mi "0" "Back"
        _rule
        echo ""
        printf "  ${C_PROMPT}❯${CR} Choose ${C_DIM}[0-3]${CR}: "
        read -r choice
        case "$choice" in
            1) db_show_password "$cfg" ;;
            2) db_change_password "$cfg" ;;
            3) pma_port_screen "$dir" "$domain" ;;
            0) show_menu; return 0 ;;
        esac
    done
}

# Whole-server pre-flight before installing

preflight() {
    local ok=1
    _sec "Pre-flight checks"

    if command -v apt-get >/dev/null 2>&1; then
        _kv "Package mgr" "$(_dot ok) ${C_OK}apt detected${CR}"
    else
        _kv "Package mgr" "$(_dot bad) ${C_BAD}apt not found (Ubuntu/Debian required)${CR}"; ok=0
    fi

    # Supported: Ubuntu 22.04 / 24.04 / 26.04 (newer releases pass with a note).
    detect_os
    local maj; maj=$(os_major)
    if [ "$OS_ID" = "ubuntu" ]; then
        case "$OS_VERSION_ID" in
            22.04|24.04|26.04) _kv "OS" "$(_dot ok) ${C_OK}${OS_PRETTY}${CR}" ;;
            *)
                if [ "$maj" -ge 26 ]; then
                    _kv "OS" "$(_dot ok) ${C_OK}${OS_PRETTY}${CR} ${C_DIM}(newer than tested)${CR}"
                elif [ "$maj" -ge 20 ]; then
                    _kv "OS" "$(_dot warn) ${C_WARN}${OS_PRETTY} (untested; 22.04/24.04/26.04 recommended)${CR}"
                elif [ "$maj" -eq 0 ]; then
                    # No usable VERSION_ID (dev snapshot, trimmed image): warn, don't block.
                    _kv "OS" "$(_dot warn) ${C_WARN}${OS_PRETTY} (version unknown; 22.04/24.04/26.04 recommended)${CR}"
                else
                    _kv "OS" "$(_dot bad) ${C_BAD}${OS_PRETTY} (too old; use 22.04, 24.04 or 26.04)${CR}"; ok=0
                fi
                ;;
        esac
    elif [ "$OS_ID" = "debian" ]; then
        _kv "OS" "$(_dot warn) ${C_WARN}${OS_PRETTY} (untested; Ubuntu 22.04/24.04/26.04 recommended)${CR}"
    else
        _kv "OS" "$(_dot warn) ${C_WARN}${OS_PRETTY:-unknown} (untested)${CR}"
    fi

    local arch; arch=$(uname -m)
    case "$arch" in
        x86_64|amd64|aarch64|arm64) _kv "Arch" "$(_dot ok) ${C_OK}${arch}${CR}" ;;
        *) _kv "Arch" "$(_dot warn) ${C_WARN}${arch} (untested)${CR}" ;;
    esac

    local free_mb; free_mb=$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}')
    if [ "${free_mb:-0}" -ge 2048 ]; then
        _kv "Disk free" "$(_dot ok) ${C_OK}${free_mb} MB${CR}"
    else
        _kv "Disk free" "$(_dot bad) ${C_BAD}${free_mb:-0} MB (need >= 2048 MB)${CR}"; ok=0
    fi

    local mem; mem=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}')
    if [ "${mem:-0}" -ge 900 ]; then
        _kv "RAM" "$(_dot ok) ${C_OK}${mem} MB${CR}"
    else
        _kv "RAM" "$(_dot warn) ${C_WARN}${mem:-0} MB (low; MySQL may struggle)${CR}"
    fi

    if ensure_connectivity; then
        _kv "Network" "$(_dot ok) ${C_OK}online${CR}"
    else
        _kv "Network" "$(_dot bad) ${C_BAD}offline (cannot reach GitHub/Telegram)${CR}"; ok=0
    fi

    local b80 b443
    b80=$(ss -ltnH 'sport = :80' 2>/dev/null | head -1)
    b443=$(ss -ltnH 'sport = :443' 2>/dev/null | head -1)
    if [ -n "$b80" ] || [ -n "$b443" ]; then
        _kv "Ports 80/443" "$(_dot warn) ${C_WARN}in use (will be freed for Apache/SSL)${CR}"
    else
        _kv "Ports 80/443" "$(_dot ok) ${C_OK}free${CR}"
    fi

    if [ "$ok" -ne 1 ]; then
        echo ""
        echo -e "  ${C_BAD}●${CR} ${C_BAD}Pre-flight checks failed. Aborting to avoid a broken install.${CR}"
        return 1
    fi
    return 0
}

function install_bot() {
    BOT_DIR="/var/www/html/mirzaprobotconfig"
    PHP_VER="$(state_get PHP_VER)"
    [ -z "$PHP_VER" ] && PHP_VER="8.2"

    # ── Guard: only block when a PREVIOUS install fully COMPLETED ──
    if [ -f "$CONFIG_FILE_DEFAULT" ] && ! has_resumable_state; then
        clear 2>/dev/null || true
        banner
        _sec "Install blocked"
        printf "    ${C_BAD}●${CR} ${C_BAD}Mirza is already installed on this server.${CR}\n"
        printf "    ${C_DIM}Path:${CR} %s\n" "$BOT_DIR_DEFAULT"
        echo ""
        echo ""
        printf "    ${C_KEY}[1]${CR} ${C_TXT}Update it instead${CR}\n"
        printf "    ${C_KEY}[2]${CR} ${C_TXT}Remove it first, then reinstall${CR}\n"
        printf "    ${C_KEY}[3]${CR} ${C_TXT}Install another, independent bot on this server${CR}\n"
        printf "    ${C_KEY}[0]${CR} ${C_TXT}Back to menu${CR}\n"
        echo ""
        printf "  ${C_PROMPT}❯${CR} Choose ${C_DIM}[0-3]${CR}: "
        read -r _blocked_choice
        case "$_blocked_choice" in
            1) update_bot; return 0 ;;
            2) remove_bot; return 0 ;;
            3) install_second_bot; return 0 ;;
            *) show_menu; return 1 ;;
        esac
    fi
    # ── Fresh-server requirement (only on a brand-new install) ──
    if ! has_resumable_state && [ ! -f "$CONFIG_FILE_DEFAULT" ]; then
        if ! precheck_fresh_server; then
            echo ""
            printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
            read -r _
            show_menu
            return 1
        fi
    fi

    # ── Resume detector: an unfinished install is on disk ──
    if has_resumable_state; then
        clear 2>/dev/null || true
        banner
        _sec "Resume install"
        local _last
        _last="$(grep '^PHASE:' "$STATE_FILE" 2>/dev/null | tail -1 | cut -d: -f2)"
        [ -z "$_last" ] && _last="dependencies"
        printf "    ${C_WARN}●${CR} ${C_WARN}An unfinished installation was found.${CR}\n"
        printf "    ${C_DIM}Last completed step:${CR} ${C_KEY}%s${CR}\n" "$_last"
        echo ""
        printf "    ${C_KEY}[1]${CR} ${C_TXT}Resume from where it stopped${CR}\n"
        printf "    ${C_KEY}[2]${CR} ${C_TXT}Start fresh from the beginning${CR}\n"
        printf "    ${C_KEY}[0]${CR} ${C_TXT}Back to menu${CR}\n"
        echo ""
        printf "  ${C_PROMPT}❯${CR} Your choice: "
        read -r _resume_choice
        case "$_resume_choice" in
            2)
                state_clear
                [ -d "$BOT_DIR" ] && sudo rm -rf "$BOT_DIR"
                echo -e "  ${C_DIM}Starting from scratch...${CR}"; sleep 1 ;;
            0) show_menu; return 0 ;;
            *) echo -e "  ${C_OK}●${CR} ${C_OK}Resuming installation from the last step...${CR}"; sleep 1 ;;
        esac
    fi
    state_init
    state_set STARTED 1   # mark install as in-progress -> future re-runs resume (skip fresh-check)
    plan_eta   # count pending steps + estimate total time left

    # ── Pre-flight checks (network/DNS/disk/ram/ports) ──
    clear 2>/dev/null || true
    banner
    if ! preflight; then
        echo ""
        printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
        read -r _
        show_menu
        return 1
    fi

    # ╭──────────────────────── PHASE: DEPS ────────────────────────╮
    if ! phase_done DEPS; then
        # Choose which version to install (only needed before files are fetched)
        echo ""
        choose_source
        local _rc=$?
        if [ "$_rc" -eq 2 ]; then show_menu; return 0; fi
        if [ "$_rc" -ne 0 ]; then sleep 2; show_menu; return 1; fi
        state_set SRC_ZIP_URL "$SRC_ZIP_URL"
        state_set SRC_LABEL "$SRC_LABEL"
        echo ""
        echo -e "  ${C_DIM}Install target:${CR} ${C_KEY}${SRC_LABEL}${CR}"
        sleep 1

        print_header "Installing Dependencies"

        run_step "Preparing package manager (clearing stale apt locks)" "apt_recover" \
            || { show_step_error; install_pause "Preparing package manager"; }

        if ! run_step "Adding PHP repository (ondrej/php)" "setup_php_repo"; then
            if ! run_step "Retrying PHP repository with locale override" "LC_ALL=C.UTF-8 setup_php_repo"; then
                show_step_error
                install_pause "Adding PHP repository"
            fi
        fi

        if ! run_step "Updating & upgrading system packages" "apt-get update --allow-releaseinfo-change -o DPkg::Lock::Timeout=180 && DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -o DPkg::Lock::Timeout=180"; then
            echo -e "\e[93mUpdate/upgrade failed. Attempting to fix using alternative mirrors...\033[0m"
            if fix_update_issues; then
                if ! run_step "Re-running system update after mirror fix" "apt-get update --allow-releaseinfo-change -o DPkg::Lock::Timeout=180 && DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -o DPkg::Lock::Timeout=180"; then
                    show_step_error
                    install_pause "System update/upgrade"
                fi
            else
                install_pause "System update/upgrade (mirror fix failed)"
            fi
        fi

        run_step "Installing base tools (git, curl, wget, unzip, jq)" \
            "apt-get install -y software-properties-common git unzip curl wget jq" \
            || { show_step_error; install_pause "Installing base tools"; }

        PHP_VER="$(resolve_php_ver)"; [ -z "$PHP_VER" ] && PHP_VER="8.2"
        state_set PHP_VER "$PHP_VER"
        echo -e "  ${C_DIM}Selected PHP version:${CR} ${C_KEY}${PHP_VER}${CR}"

        run_step "Installing PHP ${PHP_VER} (fpm + mysql)" \
            "DEBIAN_FRONTEND=noninteractive apt install -y php${PHP_VER} php${PHP_VER}-cli php${PHP_VER}-fpm php${PHP_VER}-mysql" \
            || { show_step_error; install_pause "Installing PHP ${PHP_VER}"; }

        # Versioned packages only: unversioned (php-*, lamp-server^) would pull the
        # newest PHP available as default and break the bot's mysqli/curl.
        WEBSTACK_CMD="DEBIAN_FRONTEND=noninteractive apt install -y mysql-server apache2 libapache2-mod-php${PHP_VER} php${PHP_VER}-mbstring php${PHP_VER}-zip php${PHP_VER}-gd php${PHP_VER}-curl php${PHP_VER}-intl php${PHP_VER}-xml php${PHP_VER}-bcmath"
        if ! run_step "Installing web stack (Apache, MySQL, PHP modules)" "$WEBSTACK_CMD"; then
            # Most common cause: a broken/half-configured MySQL from an interrupted run.
            # Safe to repair here because the fresh-server check ran and no DB exists yet.
            run_step "Repairing broken MySQL installation" "repair_mysql" \
                || { show_step_error; install_pause "Repairing MySQL"; }
            run_step "Re-installing web stack" "$WEBSTACK_CMD" \
                || { show_step_error; install_pause "Installing web stack"; }
        fi

        local _other_php="" _pv
        for _pv in 8.5 8.4 8.3 8.2 8.1 8.0 7.4; do
            [ "$_pv" = "$PHP_VER" ] || _other_php="$_other_php php$_pv"
        done
        run_step "Setting PHP ${PHP_VER} as the active version" \
            "a2dismod${_other_php} mpm_event mpm_worker 2>/dev/null; a2enmod php${PHP_VER} mpm_prefork 2>/dev/null; update-alternatives --set php /usr/bin/php${PHP_VER} 2>/dev/null; systemctl restart apache2" \
            || { show_step_error; install_pause "Setting PHP ${PHP_VER} as default"; }

        echo 'phpmyadmin phpmyadmin/dbconfig-install boolean true' | sudo debconf-set-selections
        echo 'phpmyadmin phpmyadmin/app-password-confirm password mirzahipass' | sudo debconf-set-selections
        echo 'phpmyadmin phpmyadmin/mysql/admin-pass password mirzahipass' | sudo debconf-set-selections
        echo 'phpmyadmin phpmyadmin/mysql/app-pass password mirzahipass' | sudo debconf-set-selections
        echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2' | sudo debconf-set-selections
        run_step "Installing phpMyAdmin" \
            "DEBIAN_FRONTEND=noninteractive apt-get install -y phpmyadmin" \
            || { show_step_error; install_pause "Installing phpMyAdmin"; }

        if [ -f /etc/apache2/conf-available/phpmyadmin.conf ]; then
            sudo rm -f /etc/apache2/conf-available/phpmyadmin.conf
        fi
        sudo ln -s /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf || {
            echo -e "\e[91mError: Failed to create symbolic link for phpMyAdmin configuration.\033[0m"
            install_pause "phpMyAdmin symlink"
        }

        # PHP defaults to a 2M upload limit, which rejects the bot's own DB backups
        # in phpMyAdmin ("No data was received to import") once the site has any real data.
        BUMP_PHP_LIMITS_CMD="for f in \"/etc/php/${PHP_VER}/apache2/php.ini\" \"/etc/php/${PHP_VER}/fpm/php.ini\"; do [ -f \"\$f\" ] || continue; sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 200M/' \"\$f\"; sed -i 's/^post_max_size = .*/post_max_size = 200M/' \"\$f\"; sed -i 's/^memory_limit = .*/memory_limit = 256M/' \"\$f\"; sed -i 's/^max_execution_time = .*/max_execution_time = 300/' \"\$f\"; sed -i 's/^max_input_time = .*/max_input_time = 300/' \"\$f\"; done; systemctl restart apache2; systemctl restart php${PHP_VER}-fpm 2>/dev/null || true"
        run_step "Raising PHP upload/post size limits (so phpMyAdmin backup restores don't fail)" \
            "$BUMP_PHP_LIMITS_CMD" \
            || { show_step_error; install_pause "Raising PHP upload limits"; }

        run_step "Installing extra modules (php-soap, php-ssh2, libssh2)" \
            "DEBIAN_FRONTEND=noninteractive apt-get install -y php${PHP_VER}-soap php${PHP_VER}-ssh2 libssh2-1-dev libssh2-1" \
            || { show_step_error; install_pause "Installing extra PHP modules"; }

        run_step "Enabling & starting services (MySQL, Apache)" \
            "systemctl enable mysql.service && systemctl start mysql.service && systemctl enable apache2 && systemctl start apache2" \
            || { show_step_error; install_pause "Enabling core services"; }

        run_step "Configuring firewall (UFW + Apache)" \
            "apt-get install -y ufw && ufw allow 'Apache'" \
            || { show_step_error; install_pause "Configuring UFW"; }
        run_step "Restarting Apache" "systemctl restart apache2" \
            || { show_step_error; install_pause "Restarting Apache"; }

        mark_phase DEPS
    else
        echo -e "  ${C_OK}●${CR} ${C_DIM}Dependencies already installed - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ╭──────────────────────── PHASE: FILES ───────────────────────╮
    if ! phase_done FILES; then
        print_header "Downloading Bot Files"
        ZIP_URL="$(state_get SRC_ZIP_URL)"; [ -z "$ZIP_URL" ] && ZIP_URL="$SRC_ZIP_URL"
        SRC_LABEL_RESUME="$(state_get SRC_LABEL)"; [ -z "$SRC_LABEL_RESUME" ] && SRC_LABEL_RESUME="$SRC_LABEL"
        if [ -d "$BOT_DIR" ]; then
            sudo rm -rf "$BOT_DIR" || {
                echo -e "\e[91mError: Failed to remove existing directory $BOT_DIR.\033[0m"
                install_pause "Cleaning bot directory"
            }
        fi
        sudo mkdir -p "$BOT_DIR"
        if [ ! -d "$BOT_DIR" ]; then
            echo -e "\e[91mError: Failed to create directory $BOT_DIR.\033[0m"
            install_pause "Creating bot directory"
        fi

        TEMP_DIR="/tmp/mirzaprobot"
        rm -rf "$TEMP_DIR"; mkdir -p "$TEMP_DIR"
        run_step "Downloading Mirza (${SRC_LABEL_RESUME})" "wget -O '$TEMP_DIR/bot.zip' '$ZIP_URL'" \
            || { show_step_error; install_pause "Downloading bot files"; }
        run_step "Extracting source files" "unzip -o '$TEMP_DIR/bot.zip' -d '$TEMP_DIR'" \
            || { show_step_error; install_pause "Extracting bot files"; }

        EXTRACTED_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
        if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
            echo -e "\e[91mError: Extracted source folder not found (bad or empty download).\033[0m"
            install_pause "Locating extracted files"
        fi
        mv "$EXTRACTED_DIR"/* "$BOT_DIR" || {
            echo -e "\e[91mError: Failed to move extracted files.\033[0m"
            install_pause "Moving bot files"
        }
        rm -rf "$TEMP_DIR"
        sudo chown -R www-data:www-data "$BOT_DIR"
        sudo chmod -R 755 "$BOT_DIR"
        wait
        run_step "Installing PHP dependencies (composer)" "install_php_deps '$BOT_DIR'" \
            || { show_step_error; install_pause "Installing PHP dependencies"; }
        mark_phase FILES
    else
        echo -e "  ${C_OK}●${CR} ${C_DIM}Bot files already downloaded - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ╭──────────────────────── PHASE: DBROOT ──────────────────────╮
    if ! phase_done DBROOT; then
        if [ ! -f "/root/confmirza/dbrootmirza.txt" ] || ! grep -q '\$pass' /root/confmirza/dbrootmirza.txt 2>/dev/null; then
            run_step "Configuring MySQL root access" "setup_mysql_root" \
                || { show_step_error; install_pause "MySQL root setup"; }
        fi
        mark_phase DBROOT
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ── Domain capture (needed for SSL, VHost, config & webhook) ──
    clear 2>/dev/null || true
    print_header "SSL Certificate Setup"
    domainname="$(state_get DOMAIN)"
    if [ -n "$domainname" ]; then
        echo -e "  ${C_DIM}Domain (resumed):${CR} ${C_KEY}${domainname}${CR}"
    else
        if [ -n "$ARG_DOMAIN" ]; then
            domainname="$ARG_DOMAIN"
            echo -e "  ${C_DIM}Domain (from --domain):${CR} ${C_KEY}${domainname}${CR}"
        else
            read -p "Enter the domain: " domainname
        fi
        while ! validate_domain "$domainname"; do
            echo -e "\e[91mInvalid domain. Enter a full domain like bot.example.com (no http://, no slash).\033[0m"
            read -p "Enter the domain: " domainname
        done
        # Verify the domain actually points to this server (certbot needs this)
        domain_points_here "$domainname"
        case $? in
            0) echo -e "  ${C_OK}●${CR} ${C_OK}Domain resolves to this server.${CR}" ;;
            1) echo -e "  ${C_WARN}!${CR} ${C_WARN}Domain does NOT point to this server's IP ($(get_server_ip)).${CR}"
               echo -e "  ${C_DIM}Let's Encrypt will fail until the DNS A record points here.${CR}"
               printf "  ${C_PROMPT}❯${CR} Continue anyway? ${C_DIM}[y/N]${CR}: "
               read -r _gd
               if [[ ! "$_gd" =~ ^[Yy]$ ]]; then echo -e "  ${C_BAD}Aborted. Fix the DNS A record and retry.${CR}"; sleep 1; show_menu; return 1; fi ;;
            2) echo -e "  ${C_WARN}!${CR} ${C_WARN}Could not resolve the domain yet (DNS may still be propagating).${CR}"
               printf "  ${C_PROMPT}❯${CR} Continue anyway? ${C_DIM}[y/N]${CR}: "
               read -r _gd
               if [[ ! "$_gd" =~ ^[Yy]$ ]]; then echo -e "  ${C_BAD}Aborted.${CR}"; sleep 1; show_menu; return 1; fi ;;
        esac
        state_set DOMAIN "$domainname"
    fi
    DOMAIN_NAME="$domainname"
    PATHS=$(cat /root/confmirza/dbrootmirza.txt | grep '$path' | cut -d"'" -f2)

    # ╭──────────────────────── PHASE: SSL ─────────────────────────╮
    if ! phase_done SSL; then
        if [ -f "/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem" ]; then
            echo -e "  ${C_OK}●${CR} ${C_DIM}SSL certificate for ${DOMAIN_NAME} already exists - skipping issuance.${CR}"
        else
            run_step "Opening firewall ports 80 & 443" "ufw allow 80 && ufw allow 443" \
                || { show_step_error; install_pause "Opening firewall ports"; }
            run_step "Stopping Apache for certificate issuance" "systemctl stop apache2 && systemctl disable apache2" \
                || { show_step_error; install_pause "Stopping Apache"; }
            run_step "Installing Let's Encrypt (certbot)" "apt install letsencrypt -y && systemctl enable certbot.timer" \
                || { show_step_error; install_pause "Installing certbot"; }

            run_step "Requesting SSL certificate (Let's Encrypt)" \
                "certbot certonly --standalone --non-interactive --agree-tos --register-unsafely-without-email --preferred-challenges http -d $DOMAIN_NAME" \
                || { show_step_error; install_pause "Requesting SSL certificate"; }
        fi
        run_step "Enabling & starting Apache" "systemctl enable apache2 && systemctl start apache2" \
            || { show_step_error; install_pause "Starting Apache"; }
        mark_phase SSL
    else
        echo -e "  ${C_OK}●${CR} ${C_DIM}SSL certificate already configured - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ╭──────────────────────── PHASE: VHOST ───────────────────────╮
    if ! phase_done VHOST; then
        VHOST_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}.conf"
        sudo tee "$VHOST_FILE" > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN_NAME
    DocumentRoot $BOT_DIR
    <Directory $BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
        VHOST_SSL_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}-ssl.conf"
        sudo tee "$VHOST_SSL_FILE" > /dev/null <<EOF
<VirtualHost *:443>
    ServerName $DOMAIN_NAME
    DocumentRoot $BOT_DIR
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem
    <Directory $BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
        run_step "Configuring Apache virtual hosts" \
            "a2ensite '${DOMAIN_NAME}.conf' && a2ensite '${DOMAIN_NAME}-ssl.conf' ; a2dissite 000-default.conf 2>/dev/null ; a2dissite 000-default-le-ssl.conf 2>/dev/null ; a2dissite default-ssl.conf 2>/dev/null ; rm -f /etc/apache2/sites-enabled/000-default.conf /etc/apache2/sites-enabled/000-default-le-ssl.conf /etc/apache2/sites-enabled/default-ssl.conf ; rm -f /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/000-default-le-ssl.conf /etc/apache2/sites-available/default-ssl.conf ; a2enmod ssl ; a2enmod rewrite ; systemctl restart apache2" \
            || { show_step_error; install_pause "Configuring Apache virtual hosts"; }
        mark_phase VHOST
    else
        echo -e "  ${C_OK}●${CR} ${C_DIM}Apache virtual hosts already configured - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ── Bot configuration inputs (token / chat id / botname) ──
    clear 2>/dev/null || true
    print_header "Bot Configuration"
    YOUR_BOT_TOKEN="$(state_get BOT_TOKEN)"
    if [ -n "$YOUR_BOT_TOKEN" ]; then
        echo -e "\e[33m[+] \e[36mBot Token (resumed):\e[0m ${YOUR_BOT_TOKEN:0:10}..."
    else
        if [ -n "$ARG_TOKEN" ]; then
            YOUR_BOT_TOKEN="$ARG_TOKEN"
            echo -e "\e[33m[+] \e[36mBot Token (from --token):\e[0m ${YOUR_BOT_TOKEN:0:10}..."
        else
            printf "\e[33m[+] \e[36mBot Token: \033[0m"
            read YOUR_BOT_TOKEN
        fi
        while [[ ! "$YOUR_BOT_TOKEN" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]]; do
            echo -e "\e[91mInvalid bot token format. Please try again.\033[0m"
            printf "\e[33m[+] \e[36mBot Token: \033[0m"
            read YOUR_BOT_TOKEN
        done
        # Live-verify the token with Telegram (getMe)
        while true; do
            validate_token "$YOUR_BOT_TOKEN"
            case $? in
                0) echo -e "  ${C_OK}●${CR} ${C_OK}Token verified with Telegram.${CR}"; break ;;
                2) echo -e "  ${C_BAD}●${CR} ${C_BAD}Telegram rejected this token (or API unreachable).${CR}"
                   printf "  ${C_PROMPT}❯${CR} Re-enter token, or press Enter to keep it anyway: "
                   read -r _t
                   if [ -z "$_t" ]; then break; fi
                   YOUR_BOT_TOKEN="$_t"
                   while [[ ! "$YOUR_BOT_TOKEN" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]]; do
                       echo -e "\e[91mInvalid format.\033[0m"; printf "  ${C_PROMPT}❯${CR} Bot Token: "; read -r YOUR_BOT_TOKEN
                   done ;;
                *) break ;;
            esac
        done
        state_set BOT_TOKEN "$YOUR_BOT_TOKEN"
    fi

    YOUR_CHAT_ID="$(state_get CHAT_ID)"
    if [ -n "$YOUR_CHAT_ID" ]; then
        echo -e "\e[33m[+] \e[36mChat id (resumed):\e[0m ${YOUR_CHAT_ID}"
    else
        if [ -n "$ARG_ADMIN" ]; then
            YOUR_CHAT_ID="$ARG_ADMIN"
            echo -e "\e[33m[+] \e[36mChat id (from --admin):\e[0m ${YOUR_CHAT_ID}"
        else
            printf "\e[33m[+] \e[36mChat id: \033[0m"
            read YOUR_CHAT_ID
        fi
        while [[ ! "$YOUR_CHAT_ID" =~ ^-?[0-9]+$ ]]; do
            echo -e "\e[91mInvalid chat ID format. Please try again.\033[0m"
            printf "\e[33m[+] \e[36mChat id: \033[0m"
            read YOUR_CHAT_ID
        done
        state_set CHAT_ID "$YOUR_CHAT_ID"
    fi

    YOUR_DOMAIN="$DOMAIN_NAME"
    YOUR_BOTNAME="$(state_get BOTNAME)"
    if [ -n "$YOUR_BOTNAME" ]; then
        echo -e "\e[33m[+] \e[36musernamebot (resumed):\e[0m ${YOUR_BOTNAME}"
    else
        if [ -n "$ARG_NAME" ]; then
            YOUR_BOTNAME="$ARG_NAME"
            echo -e "\e[33m[+] \e[36musernamebot (from --name):\e[0m ${YOUR_BOTNAME}"
        else
            while true; do
                printf "\e[33m[+] \e[36musernamebot: \033[0m"
                read YOUR_BOTNAME
                if [ "$YOUR_BOTNAME" != "" ]; then
                    break
                else
                    echo -e "\e[91mError: Bot username cannot be empty. Please enter a valid username.\033[0m"
                fi
            done
        fi
        YOUR_BOTNAME="${YOUR_BOTNAME#@}"
        YOUR_BOTNAME="${YOUR_BOTNAME//[[:space:]]/}"
        state_set BOTNAME "$YOUR_BOTNAME"
    fi

    YOUR_BOTLABEL="$(state_get BOTLABEL)"
    if [ -n "$YOUR_BOTLABEL" ]; then
        echo -e "\e[33m[+] \e[36mBot name (resumed):\e[0m ${YOUR_BOTLABEL}"
    else
        printf "\e[33m[+] \e[36mName for this bot ${CR}${C_DIM}[default: ${YOUR_BOTNAME}]${CR}: "
        read YOUR_BOTLABEL
        [ -z "$YOUR_BOTLABEL" ] && YOUR_BOTLABEL="$YOUR_BOTNAME"
        state_set BOTLABEL "$YOUR_BOTLABEL"
    fi

    PMA_PORT="$(state_get PMA_PORT)"
    if [ -n "$PMA_PORT" ]; then
        echo -e "\e[33m[+] \e[36mphpMyAdmin port (resumed):\e[0m ${PMA_PORT}"
    else
        ask_pma_port
        state_set PMA_PORT "$PMA_PORT"
    fi

    ROOT_PASSWORD=$(cat /root/confmirza/dbrootmirza.txt | grep '$pass' | cut -d"'" -f2)
    ROOT_USER="root"
    echo "SELECT 1" | mysql -u$ROOT_USER -p$ROOT_PASSWORD 2>/dev/null || {
        echo -e "\e[91mError: MySQL connection failed.\033[0m"
        install_pause "MySQL connection"
    }

    randomdbpass=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-8)
    randomdbdb=$(openssl rand -base64 10 | tr -dc 'a-zA-Z' | cut -c1-8)
    dbname="mirzaprobot"

    # ╭──────────────────────── PHASE: DB ──────────────────────────╮
    if ! phase_done DB; then
        dbuser="$(state_get DBUSER)"
        dbpass="$(state_get DBPASS)"
        if [ -z "$dbuser" ] || [ -z "$dbpass" ]; then
            clear 2>/dev/null || true
            if [ -n "$ARG_DBUSER" ]; then
                dbuser="$ARG_DBUSER"
                echo -e "\e[32mDatabase username (from --db-user):\e[0m ${dbuser}"
            else
                echo -e "\n\e[32mPlease enter the database username!\033[0m"
                printf "[+] Default user name is \e[91m${randomdbdb}\e[0m ( let it blank to use this user name ): "
                read dbuser
            fi
            if [ "$dbuser" = "" ]; then
                dbuser=$randomdbdb
            fi
            if ! valid_db_ident "$dbuser"; then
                echo -e "  ${C_WARN}!${CR} ${C_WARN}Invalid DB username (use only A-Z a-z 0-9 _). Using generated name.${CR}"
                dbuser=$randomdbdb
            fi
            if [ -n "$ARG_DBPASS" ]; then
                dbpass="$ARG_DBPASS"
                echo -e "\e[32mDatabase password (from --db-pass): [hidden]\033[0m"
            else
                echo -e "\n\e[32mPlease enter the database password!\033[0m"
                printf "[+] Default password is \e[91m${randomdbpass}\e[0m ( let it blank to use this password ): "
                read dbpass
            fi
            if [ "$dbpass" = "" ]; then
                dbpass=$randomdbpass
            fi
            if ! valid_db_pass "$dbpass"; then
                echo -e "  ${C_WARN}!${CR} ${C_WARN}Password has unsafe characters or is too short (need 6+, A-Z a-z 0-9 _). Using generated password.${CR}"
                dbpass=$randomdbpass
            fi
            state_set DBUSER "$dbuser"
            state_set DBPASS "$dbpass"
        else
            echo -e "  ${C_OK}●${CR} ${C_DIM}Database credentials resumed.${CR}"
        fi
        # Idempotent: safe to re-run (IF NOT EXISTS), so a resumed install never breaks here
        run_step "Creating database & user" \
            "mysql -u root -p$ROOT_PASSWORD -e \"CREATE DATABASE IF NOT EXISTS $dbname;\" && mysql -u root -p$ROOT_PASSWORD -e \"CREATE USER IF NOT EXISTS '$dbuser'@'%' IDENTIFIED WITH mysql_native_password BY '$dbpass'; GRANT ALL PRIVILEGES ON $dbname.* TO '$dbuser'@'%'; FLUSH PRIVILEGES;\" && mysql -u root -p$ROOT_PASSWORD -e \"CREATE USER IF NOT EXISTS '$dbuser'@'localhost' IDENTIFIED WITH mysql_native_password BY '$dbpass'; GRANT ALL PRIVILEGES ON $dbname.* TO '$dbuser'@'localhost'; FLUSH PRIVILEGES;\"" \
            || { show_step_error; install_pause "Creating database/user"; }
        mark_phase DB
    else
        dbuser="$(state_get DBUSER)"
        dbpass="$(state_get DBPASS)"
        echo -e "  ${C_OK}●${CR} ${C_DIM}Database already created - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ╭──────────────────────── PHASE: CONFIG ──────────────────────╮
    if ! phase_done CONFIG; then
        wait
        sleep 1
        file_path="/var/www/html/mirzaprobotconfig/config.php"
        if [ -f "$file_path" ]; then
            rm "$file_path" || {
                echo -e "\e[91mError: Failed to delete old config.php.\033[0m"
                install_pause "Removing old config.php"
            }
        fi
        sleep 1
        secrettoken="$(state_get SECRET)"
        if [ -z "$secrettoken" ]; then
            secrettoken=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-8)
            state_set SECRET "$secrettoken"
        fi
        cat <<EOF > /var/www/html/mirzaprobotconfig/config.php
<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
\$request_exec_timeout = null;
\$dbhost = 'localhost';
\$dbname = '$dbname';
\$usernamedb = '$dbuser';
\$passworddb = '$dbpass';
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (\$connect->connect_error) { die("error" . \$connect->connect_error); }
mysqli_set_charset(\$connect, "utf8mb4");
\$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (\PDOException \$e) { error_log("Database connection failed: " . \$e->getMessage()); }
\$APIKEY = '${YOUR_BOT_TOKEN}';
\$adminnumber = '${YOUR_CHAT_ID}';
\$domainhosts = '${YOUR_DOMAIN}';
\$usernamebot = '${YOUR_BOTNAME}';
?>
EOF
        sudo chown www-data:www-data /var/www/html/mirzaprobotconfig/config.php 2>/dev/null
        mark_phase CONFIG
    else
        secrettoken="$(state_get SECRET)"
        echo -e "  ${C_OK}●${CR} ${C_DIM}config.php already written - skipping.${CR}"
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ╭──────────────────────── PHASE: WEBHOOK ─────────────────────╮
    if ! phase_done WEBHOOK; then
        sleep 1
        run_step "Starting Apache" "systemctl start apache2" \
            || { show_step_error; install_pause "Starting Apache"; }
        sleep 5
        # Self-heal a state left by an install that started before the
        # composer/vendor fix existed: FILES may already be marked done
        # without vendor/ actually present. A directory downloaded from a
        # release that predates composer.json being shipped at all has
        # neither file on disk, so fetch them first if still missing.
        if [ ! -f "$BOT_DIR/vendor/autoload.php" ]; then
            if [ ! -f "$BOT_DIR/composer.json" ]; then
                curl -fsSL -o "$BOT_DIR/composer.json" "https://raw.githubusercontent.com/${GIT_REPO}/master/composer.json" 2>/dev/null
                curl -fsSL -o "$BOT_DIR/composer.lock" "https://raw.githubusercontent.com/${GIT_REPO}/master/composer.lock" 2>/dev/null
            fi
            run_step "Installing PHP dependencies (composer)" "install_php_deps '$BOT_DIR'" \
                || { show_step_error; install_pause "Installing PHP dependencies"; }
        fi
        run_step "Initializing database tables" "cd '$BOT_DIR' && php${PHP_VER} table.php" \
            || { show_step_error; install_pause "Initializing database tables"; }
        # table.php ran as root; files it created (log.txt) must stay writable by Apache
        chown -R www-data:www-data "$BOT_DIR" 2>/dev/null
        # Only now - Apache is up and the schema is migrated - tell Telegram
        # about the webhook and invite the admin to send /start. Doing this
        # earlier risked a real update arriving before table.php had run.
        run_step "Setting Telegram webhook" \
            "curl -s -F \"url=https://${YOUR_DOMAIN}/index.php\" -F \"secret_token=${secrettoken}\" \"https://api.telegram.org/bot${YOUR_BOT_TOKEN}/setWebhook\"" \
            || { show_step_error; install_pause "Setting Telegram webhook"; }

        MESSAGE="✅ The Mirza bot is installed! for start the bot send /start command."
        curl -s -X POST "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/sendMessage" -d chat_id="${YOUR_CHAT_ID}" -d text="$MESSAGE" > /dev/null 2>&1
        mark_phase WEBHOOK
    fi
    # ╰─────────────────────────────────────────────────────────────╯

    # ── Done ──
    mark_phase COMPLETE
    bots_registry_add "$YOUR_BOTLABEL" "$BOT_DIR" "$YOUR_DOMAIN"
    local pma_failed=0
    pma_set_port "$BOT_DIR" "${PMA_PORT:-443}" || pma_failed=1
    clear 2>/dev/null || true
    banner
    _sec "Installation complete"
    printf "    ${C_OK}●${CR} ${C_OK}Mirza is installed and the webhook is set.${CR}\n"
    printf "    ${C_DIM}Open Telegram and send ${CR}${C_KEY}/start${CR}${C_DIM} to your bot.${CR}\n"

    _sec "Access"
    _kv "Name" "${C_DIM}${YOUR_BOTLABEL}${CR}"
    _kv "Bot URL" "${C_DIM}https://${YOUR_DOMAIN}${CR}"
    _kv "phpMyAdmin" "${C_DIM}$(pma_url "$YOUR_DOMAIN" "$(bots_registry_pma_port "$BOT_DIR")")${CR}"
    [ "$pma_failed" -eq 1 ] && printf "    ${C_WARN}!${CR} ${C_DIM}Port ${PMA_PORT} could not be used, so phpMyAdmin stayed on 443. Try another from menu 9.${CR}\n"

    _sec "Database"
    _kv "Name" "${C_KEY}${dbname}${CR}"
    _kv "Username" "${C_KEY}${dbuser}${CR}"
    _kv "Password" "${C_KEY}${dbpass}${CR}"
    printf "    ${C_WARN}!${CR} ${C_DIM}Save these credentials somewhere safe.${CR}\n"

    _sec "Manage"
    _kv "Command" "${C_DIM}run ${CR}${C_KEY}mirza${CR}${C_DIM} anytime to open this panel${CR}"
    echo ""
    _rule
    echo ""

    chmod +x /root/install.sh
    ln -sf /root/install.sh /usr/local/bin/mirza
    _ensure_selfupdate_cron
    self_update_script
}
function install_second_bot() {
    clear 2>/dev/null || true
    banner
    _sec "Install another bot"
    printf "    ${C_DIM}Sets up a second, independent Mirza Pro Max on this server:${CR}\n"
    printf "    ${C_DIM}its own directory, database and domain. Bot(s) already${CR}\n"
    printf "    ${C_DIM}installed here are not touched.${CR}\n"
    echo ""

    if [ ! -f "/root/confmirza/dbrootmirza.txt" ]; then
        printf "    ${C_BAD}●${CR} ${C_BAD}No Mirza install found on this server yet. Use option 1 (Install) first.${CR}\n"
        sleep 2; show_menu; return 1
    fi
    if ! ensure_connectivity; then
        printf "    ${C_BAD}●${CR} ${C_BAD}No internet connection. Aborting.${CR}\n"
        sleep 2; show_menu; return 1
    fi

    # ── Pick a free directory + database name ────────────────
    local n=2 NEW_BOT_DIR NEW_DB ROOT_PASSWORD
    ROOT_PASSWORD=$(cat /root/confmirza/dbrootmirza.txt 2>/dev/null | grep '$pass' | cut -d"'" -f2)
    while [ -d "/var/www/html/mirzaprobotconfig${n}" ] \
        || mysql -u root -p"$ROOT_PASSWORD" -N -e "SHOW DATABASES LIKE 'mirzaprobot${n}';" 2>/dev/null | grep -q "mirzaprobot${n}"; do
        n=$((n+1))
    done
    NEW_BOT_DIR="/var/www/html/mirzaprobotconfig${n}"
    NEW_DB="mirzaprobot${n}"
    _kv "Directory" "${C_KEY}${NEW_BOT_DIR}${CR}"
    _kv "Database"  "${C_KEY}${NEW_DB}${CR}"
    echo ""

    # ── Domain ─────────────────────────────────────────────────
    local domainname
    read -p "Enter the domain for this bot: " domainname
    while ! validate_domain "$domainname" || [ -f "/etc/apache2/sites-available/${domainname}.conf" ]; do
        if [ -f "/etc/apache2/sites-available/${domainname}.conf" ]; then
            echo -e "\e[91mThis domain already has a virtual host on this server. Use a different domain.\033[0m"
        else
            echo -e "\e[91mInvalid domain. Enter a full domain like bot2.example.com (no http://, no slash).\033[0m"
        fi
        read -p "Enter the domain: " domainname
    done
    domain_points_here "$domainname"
    case $? in
        0) echo -e "  ${C_OK}●${CR} ${C_OK}Domain resolves to this server.${CR}" ;;
        *) echo -e "  ${C_WARN}!${CR} ${C_WARN}Domain does not resolve to this server's IP ($(get_server_ip)) yet.${CR}"
           printf "  ${C_PROMPT}❯${CR} Continue anyway? SSL will fail until DNS is fixed. ${C_DIM}[y/N]${CR}: "
           read -r _gd
           [[ "$_gd" =~ ^[Yy]$ ]] || { echo -e "  ${C_BAD}Aborted.${CR}"; sleep 1; show_menu; return 1; } ;;
    esac
    local DOMAIN_NAME="$domainname"

    # ── Bot token / chat id / username ────────────────────────
    print_header "Bot Configuration"
    local YOUR_BOT_TOKEN YOUR_CHAT_ID YOUR_BOTNAME
    printf "\e[33m[+] \e[36mBot Token: \033[0m"; read YOUR_BOT_TOKEN
    while [[ ! "$YOUR_BOT_TOKEN" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]]; do
        echo -e "\e[91mInvalid bot token format. Please try again.\033[0m"
        printf "\e[33m[+] \e[36mBot Token: \033[0m"; read YOUR_BOT_TOKEN
    done
    validate_token "$YOUR_BOT_TOKEN"
    if [ $? -eq 0 ]; then
        echo -e "  ${C_OK}●${CR} ${C_OK}Token verified with Telegram.${CR}"
    else
        echo -e "  ${C_WARN}!${CR} ${C_WARN}Could not verify the token with Telegram - continuing anyway.${CR}"
    fi
    printf "\e[33m[+] \e[36mChat id: \033[0m"; read YOUR_CHAT_ID
    printf "\e[33m[+] \e[36musernamebot: \033[0m"; read YOUR_BOTNAME
    local YOUR_BOTLABEL
    printf "\e[33m[+] \e[36mName for this bot ${CR}${C_DIM}[default: ${YOUR_BOTNAME}]${CR}: "
    read YOUR_BOTLABEL
    [ -z "$YOUR_BOTLABEL" ] && YOUR_BOTLABEL="$YOUR_BOTNAME"
    local PMA_PORT
    ask_pma_port

    # ── Download & extract ────────────────────────────────────
    print_header "Downloading Bot Files"
    choose_source
    local _rc=$?
    if [ "$_rc" -eq 2 ]; then show_menu; return 0; fi
    if [ "$_rc" -ne 0 ]; then sleep 2; show_menu; return 1; fi
    local ZIP_URL="$SRC_ZIP_URL" TARGET_LABEL="$SRC_LABEL"

    mkdir -p "$NEW_BOT_DIR"
    local TEMP_DIR="/tmp/mirzaprobot_second"
    rm -rf "$TEMP_DIR"; mkdir -p "$TEMP_DIR"
    run_step "Downloading Mirza (${TARGET_LABEL})" "wget -O '$TEMP_DIR/bot.zip' '$ZIP_URL'" \
        || { show_step_error; echo -e "\033[31mDownload failed.\033[0m"; rm -rf "$TEMP_DIR"; sleep 2; show_menu; return 1; }
    run_step "Extracting source files" "unzip -o '$TEMP_DIR/bot.zip' -d '$TEMP_DIR'" \
        || { show_step_error; echo -e "\033[31mExtraction failed.\033[0m"; rm -rf "$TEMP_DIR"; sleep 2; show_menu; return 1; }
    local EXTRACTED_DIR; EXTRACTED_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
        echo -e "\033[31mExtracted folder not found.\033[0m"; rm -rf "$TEMP_DIR"; sleep 2; show_menu; return 1
    fi
    mv "$EXTRACTED_DIR"/* "$NEW_BOT_DIR"
    rm -rf "$TEMP_DIR"
    chown -R www-data:www-data "$NEW_BOT_DIR"
    chmod -R 755 "$NEW_BOT_DIR"
    run_step "Installing PHP dependencies (composer)" "install_php_deps '$NEW_BOT_DIR'" \
        || { show_step_error; echo -e "\033[31mFailed to install PHP dependencies.\033[0m"; sleep 2; show_menu; return 1; }

    # ── Database ───────────────────────────────────────────────
    local dbuser dbpass
    dbuser=$(openssl rand -base64 10 | tr -dc 'a-zA-Z' | cut -c1-8)
    dbpass=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-10)
    run_step "Creating database & user" \
        "mysql -u root -p'$ROOT_PASSWORD' -e \"CREATE DATABASE IF NOT EXISTS $NEW_DB;\" && mysql -u root -p'$ROOT_PASSWORD' -e \"CREATE USER IF NOT EXISTS '$dbuser'@'%' IDENTIFIED WITH mysql_native_password BY '$dbpass'; GRANT ALL PRIVILEGES ON $NEW_DB.* TO '$dbuser'@'%'; FLUSH PRIVILEGES;\" && mysql -u root -p'$ROOT_PASSWORD' -e \"CREATE USER IF NOT EXISTS '$dbuser'@'localhost' IDENTIFIED WITH mysql_native_password BY '$dbpass'; GRANT ALL PRIVILEGES ON $NEW_DB.* TO '$dbuser'@'localhost'; FLUSH PRIVILEGES;\"" \
        || { show_step_error; echo -e "\033[31mDatabase creation failed.\033[0m"; sleep 2; show_menu; return 1; }

    # ── config.php ─────────────────────────────────────────────
    local secrettoken; secrettoken=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-8)
    cat <<EOF > "$NEW_BOT_DIR/config.php"
<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
\$request_exec_timeout = null;
\$dbhost = 'localhost';
\$dbname = '$NEW_DB';
\$usernamedb = '$dbuser';
\$passworddb = '$dbpass';
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (\$connect->connect_error) { die("error" . \$connect->connect_error); }
mysqli_set_charset(\$connect, "utf8mb4");
\$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (\PDOException \$e) { error_log("Database connection failed: " . \$e->getMessage()); }
\$APIKEY = '${YOUR_BOT_TOKEN}';
\$adminnumber = '${YOUR_CHAT_ID}';
\$domainhosts = '${DOMAIN_NAME}';
\$usernamebot = '${YOUR_BOTNAME}';
?>
EOF
    chown www-data:www-data "$NEW_BOT_DIR/config.php"

    # ── HTTP-only vhost first (needed for the certbot --apache challenge,
    #    and lets the bot answer over plain HTTP even if SSL fails below) ──
    local VHOST_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}.conf"
    tee "$VHOST_FILE" > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN_NAME
    DocumentRoot $NEW_BOT_DIR
    <Directory $NEW_BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
    run_step "Enabling the new virtual host" "a2ensite '${DOMAIN_NAME}.conf' && systemctl reload apache2" \
        || { show_step_error; echo -e "\033[31mFailed to enable the virtual host.\033[0m"; sleep 2; show_menu; return 1; }

    # ── SSL via the Apache plugin - does not stop Apache, so the bot(s)
    #    already running keep answering the whole time ────────
    local ssl_ok=1
    if [ -f "/etc/letsencrypt/live/${DOMAIN_NAME}/fullchain.pem" ]; then
        echo -e "  ${C_OK}●${CR} ${C_DIM}SSL certificate for ${DOMAIN_NAME} already exists.${CR}"
    else
        # certbot may already be installed (bot #1's SSL phase only ever uses
        # --standalone mode), but that never pulls in the apache plugin - so
        # check for the plugin itself, not just the certbot binary.
        certbot plugins 2>/dev/null | grep -qi apache \
            || apt install -y certbot python3-certbot-apache >/dev/null 2>&1
        if ! run_step "Requesting SSL certificate (Let's Encrypt)" \
            "certbot certonly --apache --non-interactive --agree-tos --register-unsafely-without-email --preferred-challenges http -d '$DOMAIN_NAME'"; then
            show_step_error
            echo -e "  ${C_WARN}!${CR} ${C_WARN}Certificate request failed - continuing with HTTP only.${CR}"
            echo -e "  ${C_DIM}Once the domain's DNS A record points here, run: certbot certonly --apache -d ${DOMAIN_NAME}${CR}"
            ssl_ok=0
        fi
    fi
    if [ "$ssl_ok" -eq 1 ]; then
        local VHOST_SSL_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}-ssl.conf"
        tee "$VHOST_SSL_FILE" > /dev/null <<EOF
<VirtualHost *:443>
    ServerName $DOMAIN_NAME
    DocumentRoot $NEW_BOT_DIR
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem
    <Directory $NEW_BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
        run_step "Enabling the HTTPS virtual host" "a2ensite '${DOMAIN_NAME}-ssl.conf' && a2enmod ssl && systemctl reload apache2" \
            || show_step_error
    fi

    # ── table.php, then webhook - never the other way around. Telling
    #    Telegram (and the admin) the bot is ready before the schema is
    #    migrated risks a real update arriving mid-migration.  ─────
    local proto="http"; [ "$ssl_ok" -eq 1 ] && proto="https"
    run_step "Initializing database tables" "cd '$NEW_BOT_DIR' && php table.php" \
        || { show_step_error; echo -e "\033[31mtable.php failed - see the details above.\033[0m"; }
    # table.php ran as root; files it created (log.txt) must stay writable by Apache
    chown -R www-data:www-data "$NEW_BOT_DIR" 2>/dev/null
    run_step "Setting Telegram webhook" \
        "curl -s -F \"url=${proto}://${DOMAIN_NAME}/index.php\" -F \"secret_token=${secrettoken}\" \"https://api.telegram.org/bot${YOUR_BOT_TOKEN}/setWebhook\"" \
        || show_step_error
    curl -s -X POST "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/sendMessage" -d chat_id="${YOUR_CHAT_ID}" -d text="✅ The Mirza bot is installed! for start the bot send /start command." > /dev/null 2>&1
    bots_registry_add "$YOUR_BOTLABEL" "$NEW_BOT_DIR" "$DOMAIN_NAME"
    local pma_note=""
    if [ "$PMA_PORT" != "443" ]; then
        if [ "$ssl_ok" -ne 1 ]; then
            pma_note="No SSL certificate yet, so phpMyAdmin stayed on the bot's address. Change it later from menu 9."
        elif ! pma_set_port "$NEW_BOT_DIR" "$PMA_PORT"; then
            pma_note="Port ${PMA_PORT} could not be used, so phpMyAdmin stayed on the bot's address. Try another from menu 9."
        fi
    fi

    clear 2>/dev/null || true
    banner
    _sec "Another bot installed"
    printf "    ${C_OK}●${CR} ${C_OK}Set up alongside the bot(s) already on this server.${CR}\n"
    echo ""
    _kv "Name"       "${C_DIM}${YOUR_BOTLABEL}${CR}"
    _kv "Bot URL"    "${C_DIM}${proto}://${DOMAIN_NAME}${CR}"
    _kv "phpMyAdmin" "${C_DIM}$(pma_url "$DOMAIN_NAME" "$(bots_registry_pma_port "$NEW_BOT_DIR")" "$proto")${CR}"
    [ -n "$pma_note" ] && printf "    ${C_WARN}!${CR} ${C_DIM}%s${CR}\n" "$pma_note"
    _kv "Directory"  "${C_DIM}${NEW_BOT_DIR}${CR}"
    _kv "Database"   "${C_KEY}${NEW_DB}${CR}"
    _kv "DB User"    "${C_KEY}${dbuser}${CR}"
    _kv "DB Pass"    "${C_KEY}${dbpass}${CR}"
    echo ""
    printf "    ${C_WARN}!${CR} ${C_DIM}Save these credentials. Update/Backup/Remove/Renew SSL now${CR}\n"
    printf "    ${C_DIM}ask which bot when more than one is installed.${CR}\n"
    echo ""
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}
function update_bot() {
    clear 2>/dev/null || true
    banner
    if ! pick_bot_instance "update"; then
        # [0] in the bot list is "back", not "nothing installed"
        [ "$(bots_registry_count)" != "0" ] && { show_menu; return 1; }
        _sec "Update"
        printf "    ${C_BAD}●${CR} ${C_BAD}Mirza is not installed here. Use option 1 to install it first.${CR}\n"
        sleep 2
        show_menu
        return 1
    fi
    BOT_DIR="$PICKED_DIR"
    local BOT_LABEL="$PICKED_NAME"
    [ -n "$PICKED_DOMAIN" ] && [ "$PICKED_DOMAIN" != "null" ] && BOT_LABEL="$PICKED_NAME · $PICKED_DOMAIN"
    if [ ! -d "$BOT_DIR" ]; then
        _sec "Update"
        printf "    ${C_BAD}●${CR} ${C_BAD}Mirza is not installed here. Use option 1 to install it first.${CR}\n"
        sleep 2
        show_menu
        return 1
    fi

    CONFIG_PATH="$BOT_DIR/config.php"
    if [ ! -f "$CONFIG_PATH" ]; then
        _sec "Update"
        printf "    ${C_BAD}●${CR} ${C_BAD}config.php is missing - this does not look like a working install.${CR}\n"
        printf "    ${C_DIM}Updating would leave you without database credentials. Aborting.${CR}\n"
        sleep 3
        show_menu
        return 1
    fi

    # ── What are we updating FROM? ───────────────────────────
    # The original Mirza (mahdiMGF2/mirzabot) installs to this same directory
    # with the same config.php variables, so it can be upgraded in place. It
    # simply has no "version" file of ours.
    local current flavour
    current=$(get_installed_version "$BOT_DIR")
    if [ -n "$current" ]; then
        flavour="Mirza Pro Max ${current}"
    elif [ -f "$BOT_DIR/index.php" ] && [ -f "$BOT_DIR/table.php" ]; then
        flavour="original Mirza (no version file)"
        current="original"
    else
        flavour="unknown"
        current="unknown"
    fi

    _sec "Update"
    _kv "Bot" "${C_KEY}${BOT_LABEL}${CR}"
    _kv "Installed" "${C_OK}${flavour}${CR}"
    _kv "Directory" "${C_DIM}${BOT_DIR}${CR}"
    echo ""
    printf "    ${C_DIM}Your config.php, database and any files not shipped by this${CR}\n"
    printf "    ${C_DIM}project are kept. New files are written over the old ones and${CR}\n"
    printf "    ${C_DIM}the database gains its new columns - nothing is deleted.${CR}\n"

    if ! ensure_connectivity; then
        printf "\n    ${C_BAD}●${CR} ${C_BAD}No internet connection (even after a DNS reset). Try again later.${CR}\n"
        sleep 2; show_menu; return 1
    fi

    choose_source
    local _rc=$?
    if [ "$_rc" -eq 2 ]; then show_menu; return 0; fi
    if [ "$_rc" -ne 0 ]; then sleep 2; show_menu; return 1; fi
    local ZIP_URL="$SRC_ZIP_URL" TARGET_LABEL="$SRC_LABEL"

    # ── Confirm before touching anything ─────────────────────
    _sec "Ready to update"
    _kv "Bot" "${C_KEY}${BOT_LABEL}${CR}"
    _kv "Installed" "${C_OK}${flavour}${CR}"
    _kv "Target" "${C_KEY}${TARGET_LABEL}${CR}"
    echo ""
    local confirm
    if [ -n "$MIRZA_NONINTERACTIVE" ]; then
        # the admin already confirmed, in the bot, before the request was written
        confirm="y"
    else
        printf "  ${C_PROMPT}❯${CR} Update now? ${C_DIM}[y/N]${CR}: "
        read -r confirm
    fi
    case "$confirm" in
        y|Y|yes|YES|Yes) ;;
        *)
            printf "\n    ${C_DIM}Nothing was changed.${CR}\n"
            sleep 1
            show_menu
            return 0
            ;;
    esac

    print_header "Updating Mirza"

    # ── 1. Safety net: a restorable snapshot BEFORE anything moves ──
    local BK_DIR="/root/mirza-backups"
    local STAMP; STAMP=$(date +%Y%m%d_%H%M%S)
    # Named after the bot it belongs to - its @username, or its directory when
    # config.php has none - so two bots' snapshots are told apart at a glance
    # in this shared folder. Only a label: which bot a snapshot really belongs
    # to is still decided by the directory inside it, since that is where it
    # would unpack to.
    local BK_TAG
    BK_TAG=$(grep '^\$usernamebot' "$CONFIG_PATH" 2>/dev/null | cut -d"'" -f2 | tr -cd 'A-Za-z0-9_' | cut -c1-32)
    [ -z "$BK_TAG" ] && BK_TAG=$(basename "$BOT_DIR" | tr -cd 'A-Za-z0-9_' | cut -c1-32)
    local ROLLBACK="${BK_DIR}/pre-update_${BK_TAG}_${STAMP}.tar.gz"
    mkdir -p "$BK_DIR"
    run_step "Backing up the current install" \
        "tar --warning=no-file-changed -czf '$ROLLBACK' -C '$(dirname "$BOT_DIR")' --exclude='*.bak*' --exclude='.git' --exclude='log.txt' --exclude='error_log' --exclude='update_request' --exclude='update_request.running' --exclude='rollback_request' --exclude='update_progress.json' --exclude='update_status.json' --exclude='update_backups.json' '$(basename "$BOT_DIR")'" \
        || { show_step_error; printf "  ${C_BAD}Could not create a rollback archive. Refusing to continue.${CR}\n"; sleep 3; show_menu; return 1; }
    # keep this bot's 2 most recent - the bot lists exactly these for
    # "بازگشت به نسخه قبلی", so the two numbers are one number. The folder is
    # shared: another bot's snapshots (told apart by the directory inside, as
    # the bot's own list does) are never counted or deleted.
    local _kept=0 _snap
    for _snap in $(ls -1t "$BK_DIR"/pre-update_*.tar.gz 2>/dev/null); do
        [ "$(tar -tzf "$_snap" 2>/dev/null | head -1 | cut -d/ -f1)" = "$(basename "$BOT_DIR")" ] || continue
        _kept=$((_kept + 1))
        [ "$_kept" -gt 2 ] && rm -f "$_snap"
    done

    # ── 2. Fetch and validate the new code ───────────────────
    TEMP_DIR="/tmp/mirzaprobot_update"
    rm -rf "$TEMP_DIR"; mkdir -p "$TEMP_DIR"
    run_step "Downloading ${TARGET_LABEL}" \
        "curl -fsSL --max-time 180 -o '$TEMP_DIR/bot.zip' '$ZIP_URL' || wget -q -O '$TEMP_DIR/bot.zip' '$ZIP_URL'" \
        || { show_step_error; printf "  ${C_BAD}Download failed. Nothing was changed.${CR}\n"; rm -rf "$TEMP_DIR"; sleep 3; show_menu; return 1; }
    run_step "Extracting the package" "unzip -o -q '$TEMP_DIR/bot.zip' -d '$TEMP_DIR'" \
        || { show_step_error; printf "  ${C_BAD}The archive could not be extracted. Nothing was changed.${CR}\n"; rm -rf "$TEMP_DIR"; sleep 3; show_menu; return 1; }

    local NEW_DIR
    NEW_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    # Sanity-check the payload before letting it near a working bot
    if [ -z "$NEW_DIR" ] || [ ! -f "$NEW_DIR/index.php" ] || [ ! -f "$NEW_DIR/table.php" ] || [ ! -f "$NEW_DIR/admin.php" ]; then
        printf "  ${C_BAD}●${CR} ${C_BAD}The downloaded package does not look like Mirza. Aborting.${CR}\n"
        printf "  ${C_DIM}Your install was not touched.${CR}\n"
        rm -rf "$TEMP_DIR"; sleep 3; show_menu; return 1
    fi
    # never let a packaged config.php overwrite real credentials
    rm -f "$NEW_DIR/config.php"

    # ── 3. PHP dependencies - built in the extracted copy first, so a
    #      composer/network failure aborts before the live install is touched.
    run_step "Installing PHP dependencies (composer)" "install_php_deps '$NEW_DIR'" \
        || { show_step_error; printf "  ${C_BAD}Could not install PHP dependencies. Nothing was changed.${CR}\n"; rm -rf "$TEMP_DIR"; sleep 3; show_menu; return 1; }

    # ── 4. Overlay the new files (no wipe) ───────────────────
    # cp -a over the existing tree: shipped files are replaced, everything the
    # operator added (config.php, vendor/, uploads, custom panels) stays put.
    run_step "Installing new files" "cp -a '$NEW_DIR/.' '$BOT_DIR/'" \
        || { show_step_error; _rollback_update "$ROLLBACK" "$BOT_DIR"; rm -rf "$TEMP_DIR"; sleep 3; show_menu; return 1; }

    run_step "Setting ownership and permissions" \
        "chown -R www-data:www-data '$BOT_DIR' && find '$BOT_DIR' -type d -exec chmod 755 {} + && find '$BOT_DIR' -type f -exec chmod 644 {} + && chmod +x '$BOT_DIR'/*.sh 2>/dev/null; true" \
        || true

    # ── 5. Syntax-check the core before trusting it ──────────
    local BAD=""
    for f in index.php admin.php function.php keyboard.php table.php config.php; do
        [ -f "$BOT_DIR/$f" ] || continue
        php -l "$BOT_DIR/$f" >/dev/null 2>&1 || BAD="$BAD $f"
    done
    if [ -n "$BAD" ]; then
        printf "  ${C_BAD}●${CR} ${C_BAD}PHP syntax errors after update:${CR}${C_KEY}${BAD}${CR}\n"
        _rollback_update "$ROLLBACK" "$BOT_DIR"
        rm -rf "$TEMP_DIR"; sleep 4; show_menu; return 1
    fi
    printf "    ${C_OK}✔${CR} ${C_DIM}Core files pass the PHP syntax check${CR}\n"

    # ── 6. Database migration (additive, never destructive) ──
    # table.php only ever CREATEs missing tables and ADDs missing columns, so
    # it upgrades an original-Mirza schema without touching existing rows.
    local DOMAIN_URL DOMAIN_NAME
    DOMAIN_URL=$(grep "^\$domainhosts" "$CONFIG_PATH" | cut -d"'" -f2)
    DOMAIN_NAME=$(echo "$DOMAIN_URL" | cut -d'/' -f1)
    local DB_STATE=""
    if ( cd "$BOT_DIR" && php table.php >/dev/null 2>&1 ); then
        printf "    ${C_OK}✔${CR} ${C_DIM}Database schema updated${CR}\n"
        DB_STATE="به‌روزرسانی شد"
    elif [ -n "$DOMAIN_URL" ] && curl -fsS --max-time 60 "https://${DOMAIN_URL}/table.php" >/dev/null 2>&1; then
        printf "    ${C_OK}✔${CR} ${C_DIM}Database schema updated (over HTTPS)${CR}\n"
        DB_STATE="به‌روزرسانی شد"
    else
        printf "    ${C_WARN}!${CR} ${C_WARN}Could not run table.php automatically.${CR}\n"
        printf "      ${C_DIM}Open https://${DOMAIN_URL}/table.php once in a browser.${CR}\n"
        DB_STATE="به‌روزرسانی نشد - table.php رو یک بار توی مرورگر باز کن"
    fi

    # ── 7. Apache: only fix what is actually missing ─────────
    if [ -n "$DOMAIN_NAME" ]; then
        _ensure_vhost "$DOMAIN_NAME" "$BOT_DIR"
    fi

    # ── 8. Is the bot actually answering? ────────────────────
    if [ -n "$DOMAIN_NAME" ]; then
        local code
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://${DOMAIN_NAME}/" 2>/dev/null)
        if [ "$code" = "200" ] || [ "$code" = "301" ] || [ "$code" = "302" ]; then
            printf "    ${C_OK}✔${CR} ${C_DIM}Site responds (HTTP ${code})${CR}\n"
        else
            printf "    ${C_WARN}!${CR} ${C_WARN}Site returned HTTP ${code:-timeout}. Check Apache and SSL.${CR}\n"
        fi
    fi

    # ── 9. Refresh the management script itself ──────────────
    if [ -f "$BOT_DIR/install.sh" ]; then
        sed -i 's/\r$//' "$BOT_DIR/install.sh"
        if bash -n "$BOT_DIR/install.sh" 2>/dev/null; then
            install -m 0755 "$BOT_DIR/install.sh" /root/install.sh 2>/dev/null || \
                { cp "$BOT_DIR/install.sh" /root/install.sh; chmod +x /root/install.sh; }
            ln -sf /root/install.sh /usr/local/bin/mirza
        fi
    fi
    # an install that predates the button gets its watcher here, so the very
    # next update can be started from inside the bot
    _ensure_selfupdate_cron

    rm -rf "$TEMP_DIR"

    local newver; newver=$(get_installed_version "$BOT_DIR"); [ -z "$newver" ] && newver="$TARGET_LABEL"

    # ── 10. Tell the bot's admins it was updated ───────────────
    # Best effort on purpose: the update has already succeeded by this point,
    # so a message that cannot be delivered must never turn it into a failure.
    if [ -f "$BOT_DIR/config.php" ]; then
        cat > /tmp/mirza_notify.php <<'NOTIFYEOF'
<?php
// Announce a finished update to every admin in the bot's own admin table.
// Run from inside the bot directory so config.php resolves; every value it
// reports comes in through the environment, so nothing needs quoting twice.
require 'config.php';

$old    = getenv('MIRZA_OLD') ?: '?';
$new    = getenv('MIRZA_NEW') ?: '?';
$domain = getenv('MIRZA_DOMAIN') ?: '';
$db     = getenv('MIRZA_DB') ?: '';
$backup = getenv('MIRZA_BACKUP') ?: '';
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$lines = ["✅ <b>ربات آپدیت شد</b>"];
// which bot: with two on one server the same admin may hear from both
$who = ltrim(trim((string) ($usernamebot ?? '')), '@');
if ($who !== '') {
    $lines[] = "<blockquote>🤖 @" . $esc($who) . "</blockquote>";
}
$lines[] = "";
if ($domain !== '') {
    $lines[] = "دامنه: <code>" . $esc($domain) . "</code>";
}
$lines[] = "نسخه: <b>" . $esc($old) . "</b> ← <b>" . $esc($new) . "</b>";
$lines[] = "زمان: " . date('Y-m-d H:i');
if ($db !== '') {
    $lines[] = "دیتابیس: " . $esc($db);
}
if ($backup !== '') {
    $lines[] = "";
    $lines[] = "بکاپ نسخه‌ی قبلی:";
    $lines[] = "<code>" . $esc($backup) . "</code>";
}
$text = implode("\n", $lines);

$ids = [];
try {
    foreach ($pdo->query("SELECT id_admin FROM admin") as $row) {
        $id = trim((string) ($row['id_admin'] ?? ''));
        if ($id !== '' && ctype_digit($id)) {
            $ids[$id] = true;
        }
    }
} catch (Exception $e) {
    // no admin table, or no database: there is simply nobody to tell
}

$sent = 0;
foreach (array_keys($ids) as $id) {
    $ch = curl_init("https://api.telegram.org/bot{$APIKEY}/sendMessage");
    if ($ch === false) {
        continue;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $id, 'text' => $text, 'parse_mode' => 'HTML']);
    $res = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (!empty($res['ok'])) {
        $sent++;
    }
}
echo $sent;
NOTIFYEOF
        local SENT
        SENT=$( cd "$BOT_DIR" && MIRZA_OLD="$flavour" MIRZA_NEW="$newver" \
            MIRZA_DOMAIN="$DOMAIN_NAME" MIRZA_DB="$DB_STATE" MIRZA_BACKUP="$ROLLBACK" \
            php /tmp/mirza_notify.php 2>/dev/null )
        rm -f /tmp/mirza_notify.php
        if [ -n "$SENT" ] && [ "$SENT" -gt 0 ] 2>/dev/null; then
            printf "    ${C_OK}✔${CR} ${C_DIM}Update announced to ${SENT} admin(s) in Telegram${CR}\n"
        else
            printf "    ${C_DIM}· Could not announce the update in Telegram${CR}\n"
        fi
    fi
    echo ""
    _sec "Done"
    _kv "Bot"      "${C_KEY}${BOT_LABEL}${CR}"
    _kv "Was"      "${C_DIM}${flavour}${CR}"
    _kv "Now"      "${C_OK}${newver}${CR}"
    _kv "Rollback" "${C_DIM}${ROLLBACK}${CR}"
    echo ""
    printf "    ${C_DIM}Your config.php and database were kept as they were.${CR}\n"
    printf "    ${C_DIM}To undo:${CR} ${C_KEY}tar -xzf ${ROLLBACK} -C /var/www/html${CR}\n"
    echo ""
    # the success report above is the whole point of the run when nobody is
    # watching; the admin is told in Telegram by step 10 either way
    if [ -n "$MIRZA_NONINTERACTIVE" ]; then
        return 0
    fi
    printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
    read -r _
    show_menu
}

# Put the pre-update snapshot back, exactly as it was.
_rollback_update() {
    local archive="$1" botdir="$2"
    printf "  ${C_WARN}↺${CR} ${C_WARN}Rolling back to the pre-update snapshot...${CR}\n"
    if [ ! -f "$archive" ]; then
        printf "  ${C_BAD}●${CR} ${C_BAD}Snapshot missing (${archive}). Manual recovery needed.${CR}\n"
        return 1
    fi
    rm -rf "${botdir}.failed" 2>/dev/null
    mv "$botdir" "${botdir}.failed" 2>/dev/null
    if tar -xzf "$archive" -C "$(dirname "$botdir")" 2>/dev/null; then
        chown -R www-data:www-data "$botdir" 2>/dev/null
        rm -rf "${botdir}.failed"
        printf "  ${C_OK}✔${CR} ${C_OK}Rolled back. Your bot is as it was before the update.${CR}\n"
        return 0
    fi
    mv "${botdir}.failed" "$botdir" 2>/dev/null
    printf "  ${C_BAD}●${CR} ${C_BAD}Rollback failed. Snapshot kept at ${archive}${CR}\n"
    return 1
}

# ── Updates asked for from inside the bot ────────────────────
# The bot is served as www-data; everything update_bot() does needs root, so
# the bot can never run it directly. Instead its 🔄 آپدیت ربات button only
# WRITES a request file into its own directory, and this watcher - a line in
# root's crontab - is what actually updates. No sudo rights are handed to the
# web server, so the worst a compromised bot could ask for is the same update
# an admin could already have started from the terminal.
MIRZA_UPDATE_REQUEST="update_request"
MIRZA_UPDATE_STATUS="update_status.json"
MIRZA_ROLLBACK_REQUEST="rollback_request"
MIRZA_BACKUPS_LIST="update_backups.json"
# the same folder update_bot() drops its pre-update snapshot into
MIRZA_BACKUP_DIR="/root/mirza-backups"
# where the bot leaves the chat, message and already-translated wording for the
# progress bar. Written next to the request rather than inside it: the request
# is claimed and deleted early, and this has to outlive that.
MIRZA_PROGRESS_CTX="update_progress.json"

# ── The bot's own message, redrawn as the work goes ──────────
# Only root can do this. The update runs here, not in the bot, and a bot that
# is being overwritten cannot report on its own progress. Everything needed is
# read ONCE, up front, into these variables - a rollback replaces the whole bot
# directory half way through, taking the context file with it, so anything read
# lazily would be gone exactly when the finished message is due.
MZ_TOKEN=""; MZ_CHAT=""; MZ_MSG=""; MZ_LANG=""
MZ_TPL_PROGRESS=""; MZ_TPL_DONE=""; MZ_TPL_FAIL=""

_selfupdate_ctx_load() {
    local dir="$1" ctx="${dir}/${MIRZA_PROGRESS_CTX}"
    MZ_TOKEN=""; MZ_CHAT=""; MZ_MSG=""; MZ_LANG=""
    MZ_TPL_PROGRESS=""; MZ_TPL_DONE=""; MZ_TPL_FAIL=""
    [ -f "$ctx" ] || return 1
    MZ_TOKEN=$(grep '^\$APIKEY' "$dir/config.php" 2>/dev/null | cut -d"'" -f2)
    MZ_CHAT=$(jq -r '.chat_id // empty' "$ctx" 2>/dev/null)
    MZ_MSG=$(jq -r '.message_id // empty' "$ctx" 2>/dev/null)
    MZ_LANG=$(jq -r '.lang // empty' "$ctx" 2>/dev/null)
    MZ_TPL_PROGRESS=$(jq -r '.progress // empty' "$ctx" 2>/dev/null)
    MZ_TPL_DONE=$(jq -r '.done // empty' "$ctx" 2>/dev/null)
    MZ_TPL_FAIL=$(jq -r '.fail // empty' "$ctx" 2>/dev/null)
    [ -n "$MZ_TOKEN" ] && [ -n "$MZ_CHAT" ] && [ -n "$MZ_MSG" ]
}

_selfupdate_bar() {
    local pct="$1" width=10 filled i out=""
    [ "$pct" -gt 100 ] && pct=100
    [ "$pct" -lt 0 ] && pct=0
    filled=$(( pct * width / 100 ))
    for ((i = 0; i < width; i++)); do
        if [ "$i" -lt "$filled" ]; then out="${out}█"; else out="${out}░"; fi
    done
    printf '%s' "$out"
}

_selfupdate_tg_edit() {
    [ -n "$MZ_TOKEN" ] && [ -n "$MZ_CHAT" ] && [ -n "$MZ_MSG" ] || return 0
    curl -s --max-time 10 -X POST "https://api.telegram.org/bot${MZ_TOKEN}/editMessageText" \
        --data-urlencode "chat_id=${MZ_CHAT}" \
        --data-urlencode "message_id=${MZ_MSG}" \
        --data-urlencode "text=$1" \
        --data-urlencode "parse_mode=HTML" >/dev/null 2>&1
}

# Redraw at a whole percentage. Called once per finished step, never inside the
# spinner loop: Telegram rate-limits edits, and five a minute is plenty for a
# bar that only really moves seven times.
_selfupdate_progress() {
    local pct="$1" text
    [ -n "$MZ_TPL_PROGRESS" ] || return 0
    text="${MZ_TPL_PROGRESS//\{bar\}/$(_selfupdate_bar "$pct")}"
    text="${text//\{percent\}/$pct}"
    _selfupdate_tg_edit "$text"
}

# The finished message is the update screen itself - the version now installed,
# the date in the reader's own calendar, and the same menu the admin started
# from. None of that can be built here, so PHP builds it; this only decides
# when. Returns non-zero whenever that is not possible - php missing, or a
# rollback that has just restored a version predating this function - and the
# caller falls back to the plain template below.
_selfupdate_finish_php() {
    local dir="$1" kind="$2" backup="$3" ok="$4" rc
    [ -n "$MZ_CHAT" ] && [ -n "$MZ_MSG" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    grep -q 'function bot_update_finish_edit' "$dir/function.php" 2>/dev/null || return 1
    cat > /tmp/mirza_update_finish.php <<'FINEOF'
<?php
// the same set, in the same order, every cronbot entry point takes
require 'config.php';
require 'botapi.php';
require 'panels.php';
require 'function.php';
require 'jdf.php';
require 'vendor/autoload.php';
bot_update_finish_edit(
    getenv('MZ_CHAT'),
    getenv('MZ_MSG'),
    getenv('MZ_LANG'),
    getenv('MZ_BACKUP'),
    getenv('MZ_KIND'),
    getenv('MZ_OK') === '1'
);
FINEOF
    ( cd "$dir" && MZ_CHAT="$MZ_CHAT" MZ_MSG="$MZ_MSG" MZ_LANG="$MZ_LANG" \
        MZ_BACKUP="$backup" MZ_KIND="$kind" MZ_OK="$ok" \
        php /tmp/mirza_update_finish.php ) >> /tmp/mirza_selfupdate.log 2>&1
    rc=$?
    rm -f /tmp/mirza_update_finish.php
    return $rc
}

# The last word: done (with the snapshot the admin can go back to) or failed.
_selfupdate_final() {
    local which="$1" backup="$2" tpl text
    tpl="$MZ_TPL_DONE"
    [ "$which" = "fail" ] && tpl="$MZ_TPL_FAIL"
    [ -n "$tpl" ] || return 0
    text="${tpl//\{backup\}/$backup}"
    text="${text//\{bar\}/$(_selfupdate_bar 100)}"
    text="${text//\{percent\}/100}"
    _selfupdate_tg_edit "$text"
}

# Leave the bot a readable note about what happened, so its button can report
# back without needing to see this script's output.
_selfupdate_status() {
    local dir="$1" state="$2" detail="$3"
    printf '{"state":"%s","detail":"%s","at":%s}\n' "$state" "$detail" "$(date +%s)" \
        > "${dir}/${MIRZA_UPDATE_STATUS}" 2>/dev/null
    chown www-data:www-data "${dir}/${MIRZA_UPDATE_STATUS}" 2>/dev/null
    chmod 664 "${dir}/${MIRZA_UPDATE_STATUS}" 2>/dev/null
}

# Which snapshots belong to THIS bot, newest first, each with the version it
# holds - so the admin picks "the 1.0.2 from this morning" rather than a
# filename. Ownership is not cosmetic: on a two-bot server every snapshot
# lands in the same folder, and unpacking one bot's tree over another's would
# be a disaster, so the archive's own top-level directory has to match.
_selfupdate_publish_backups() {
    local dir="$1"
    local base; base=$(basename "$dir")
    local out="[" first=1 f top ver at size
    for f in $(ls -1t "$MIRZA_BACKUP_DIR"/pre-update_*.tar.gz 2>/dev/null); do
        top=$(tar -tzf "$f" 2>/dev/null | head -1 | cut -d/ -f1)
        [ "$top" = "$base" ] || continue
        ver=$(tar -xzOf "$f" "${base}/version" 2>/dev/null | tr -cd '[:alnum:]._-')
        [ -z "$ver" ] && ver="?"
        at=$(stat -c %Y "$f" 2>/dev/null || echo 0)
        size=$(stat -c %s "$f" 2>/dev/null || echo 0)
        [ "$first" -eq 1 ] || out="${out},"
        first=0
        out="${out}{\"name\":\"$(basename "$f")\",\"version\":\"${ver}\",\"at\":${at},\"size\":${size}}"
    done
    out="${out}]"
    printf '%s\n' "$out" > "${dir}/${MIRZA_BACKUPS_LIST}" 2>/dev/null
    chown www-data:www-data "${dir}/${MIRZA_BACKUPS_LIST}" 2>/dev/null
    chmod 664 "${dir}/${MIRZA_BACKUPS_LIST}" 2>/dev/null
}

selfupdate_watch() {
    bots_registry_ensure
    local dirs; dirs=$(jq -r '.[].dir' "$BOTS_REGISTRY" 2>/dev/null)
    [ -z "$dirs" ] && return 0
    local dir req claimed age rc rbreq want arc top newest rbpid rbpct newbk
    while IFS= read -r dir; do
        [ -n "$dir" ] && [ -d "$dir" ] || continue
        # A claim nobody is working on any more. Only the run that created it
        # ever removes it, so anything that ends that run some other way - a
        # crash, a reboot, or a rollback restoring a snapshot that was taken
        # while a claim was in flight - used to leave the bot's own screen
        # saying "a job is running" for ever, with no way back from inside the
        # bot. Nothing else can be running while this tick holds the lock, so an
        # old one here is by definition finished.
        claimed="${dir}/${MIRZA_UPDATE_REQUEST}.running"
        if [ -f "$claimed" ] \
            && [ $(( $(date +%s) - $(stat -c %Y "$claimed" 2>/dev/null || echo 0) )) -gt 1800 ]; then
            rm -f "$claimed"
            # deliberately no status write: whatever the last real run reported
            # is still the truth, and overwriting it would hide it
            echo "[$(date '+%F %T')] cleared a stale claim in $dir" >> /tmp/mirza_selfupdate.log
        fi
        # Refresh the list the bot shows only when a new snapshot has actually
        # appeared - rebuilding it means reading inside every archive, which is
        # not something to do every single minute for nothing.
        newest=$(ls -1t "$MIRZA_BACKUP_DIR"/pre-update_*.tar.gz 2>/dev/null | head -1)
        if [ ! -f "${dir}/${MIRZA_BACKUPS_LIST}" ] \
            || { [ -n "$newest" ] && [ "$newest" -nt "${dir}/${MIRZA_BACKUPS_LIST}" ]; }; then
            _selfupdate_publish_backups "$dir"
        fi
        # ── going back to an earlier snapshot ────────────────
        rbreq="${dir}/${MIRZA_ROLLBACK_REQUEST}"
        if [ -f "$rbreq" ]; then
            want=$(head -c 200 "$rbreq" 2>/dev/null | tr -d '\r\n')
            rm -f "$rbreq"
            # www-data wrote this name, so it is treated as hostile input: a
            # bare snapshot filename and nothing else - no slashes, no "..",
            # and it must really be one of this bot's own snapshots.
            case "$want" in
                */*|*..*|"") _selfupdate_status "$dir" "failed" "نام نسخه پشتیبان معتبر نبود"; continue ;;
                pre-update_*.tar.gz) ;;
                *) _selfupdate_status "$dir" "failed" "نام نسخه پشتیبان معتبر نبود"; continue ;;
            esac
            arc="${MIRZA_BACKUP_DIR}/${want}"
            top=$(tar -tzf "$arc" 2>/dev/null | head -1 | cut -d/ -f1)
            if [ ! -f "$arc" ] || [ "$top" != "$(basename "$dir")" ]; then
                _selfupdate_status "$dir" "failed" "نسخه پشتیبان پیدا نشد"
                continue
            fi
            # read before anything moves: _rollback_update swaps the whole
            # directory out and the context file lives inside it, so waiting
            # until the end would leave nothing to send the last message with
            _selfupdate_ctx_load "$dir"
            _selfupdate_status "$dir" "running" "در حال بازگشت به نسخه قبلی"
            _selfupdate_progress 10
            # Unpacking is one long call with no steps to count, so the bar is
            # walked forward on a timer instead - slowly, and never past 90, so
            # it cannot claim to be finished while the work is still running.
            ( _rollback_update "$arc" "$dir" >> /tmp/mirza_selfupdate.log 2>&1 ) &
            rbpid=$!
            rbpct=10
            while kill -0 "$rbpid" 2>/dev/null; do
                sleep 5
                rbpct=$(( rbpct + 10 ))
                [ "$rbpct" -gt 90 ] && rbpct=90
                _selfupdate_progress "$rbpct"
            done
            wait "$rbpid"
            rc=$?
            # the status file is written back onto the restored tree, which is
            # why it comes after the restore rather than before it
            # rebuilt FIRST: the restored tree brought its own, older copy of
            # this list back with it, and the finished screen is drawn from it
            _selfupdate_publish_backups "$dir"
            if [ "$rc" -eq 0 ]; then
                _selfupdate_status "$dir" "restored" "بازگشت به نسخه قبلی انجام شد"
                _selfupdate_finish_php "$dir" "rollback" "$want" 1 || _selfupdate_final "done" "$want"
            else
                _selfupdate_status "$dir" "failed" "بازگشت به نسخه قبلی ناموفق بود"
                _selfupdate_final "fail" "$want"
            fi
            rm -f "${dir}/${MIRZA_PROGRESS_CTX}"
            continue
        fi
        req="${dir}/${MIRZA_UPDATE_REQUEST}"
        [ -f "$req" ] || continue
        # Claim it by moving it aside FIRST. If this run is killed half way the
        # request is already gone, so the next minute can never start a second
        # update on top of one still running.
        claimed="${req}.running"
        mv -f "$req" "$claimed" 2>/dev/null || continue
        # An old request is a leftover (server was off, cron disabled). Acting
        # on it hours later would restart the bot at a moment nobody chose.
        age=$(( $(date +%s) - $(stat -c %Y "$claimed" 2>/dev/null || echo 0) ))
        if [ "$age" -gt 900 ]; then
            rm -f "$claimed"
            _selfupdate_status "$dir" "stale" "درخواست قدیمی بود و اجرا نشد"
            continue
        fi
        _selfupdate_ctx_load "$dir"
        _selfupdate_status "$dir" "running" "در حال آپدیت"
        _selfupdate_progress 0
        # STEP_TOTAL is what turns run_step's counter into a percentage. The
        # update path never set it, because a terminal gets a spinner instead -
        # seven is how many run_step calls update_bot makes, and run_step moves
        # the bar itself, so a step added later is counted without anyone
        # remembering to touch this.
        ( MIRZA_NONINTERACTIVE=1 MIRZA_BOT_DIR="$dir" STEP_TOTAL=7 update_bot ) >> /tmp/mirza_selfupdate.log 2>&1
        rc=$?
        rm -f "$claimed"
        # the snapshot this run just took is what the admin would go back to -
        # republished first so the finished message can name it
        _selfupdate_publish_backups "$dir"
        if [ "$rc" -eq 0 ]; then
            _selfupdate_status "$dir" "done" "آپدیت با موفقیت انجام شد"
            newbk=$(jq -r '.[0].name // empty' "${dir}/${MIRZA_BACKUPS_LIST}" 2>/dev/null)
            _selfupdate_finish_php "$dir" "update" "$newbk" 1 || _selfupdate_final "done" "$newbk"
        else
            # update_bot restores its own pre-update snapshot before returning
            # non-zero, so "failed" always means "still on the old version"
            _selfupdate_status "$dir" "failed" "آپدیت ناموفق بود - نسخه قبلی برگردانده شد"
            _selfupdate_final "fail" ""
        fi
        rm -f "${dir}/${MIRZA_PROGRESS_CTX}"
    done <<< "$dirs"
}

# One crontab line, added once. Matched by its command text rather than the
# whole line so a hand-edited schedule is never duplicated.
_ensure_selfupdate_cron() {
    command -v crontab >/dev/null 2>&1 || return 0
    crontab -l 2>/dev/null | grep -Fq "mirza selfupdate-watch" && return 0
    ( crontab -l 2>/dev/null; \
      echo "* * * * * flock -n /tmp/mirza_selfupdate.lock /usr/local/bin/mirza selfupdate-watch >/dev/null 2>&1" \
    ) | crontab - 2>/dev/null
}

# Create the Apache vhost only when it is missing or points somewhere else.
# An operator's customised vhost is backed up, never silently overwritten.
_ensure_vhost() {
    local domain="$1" botdir="$2"
    local vhost="/etc/apache2/sites-available/${domain}.conf"
    local vhost_ssl="/etc/apache2/sites-available/${domain}-ssl.conf"
    local need=0
    [ -f "$vhost" ] || need=1
    [ -f "$vhost" ] && ! grep -q "DocumentRoot $botdir" "$vhost" 2>/dev/null && need=1
    if [ "$need" -eq 0 ]; then
        printf "    ${C_OK}✔${CR} ${C_DIM}Apache vhost already correct - left untouched${CR}\n"
        return 0
    fi
    [ -f "$vhost" ] && cp "$vhost" "${vhost}.bak_$(date +%Y%m%d_%H%M%S)"
    tee "$vhost" >/dev/null <<EOF
<VirtualHost *:80>
    ServerName $domain
    DocumentRoot $botdir
    <Directory $botdir>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${domain}-error.log
    CustomLog \${APACHE_LOG_DIR}/${domain}-access.log combined
</VirtualHost>
EOF
    if [ -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ] && [ ! -f "$vhost_ssl" ]; then
        tee "$vhost_ssl" >/dev/null <<EOF
<VirtualHost *:443>
    ServerName $domain
    DocumentRoot $botdir
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${domain}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${domain}/privkey.pem
    <Directory $botdir>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/${domain}-error.log
    CustomLog \${APACHE_LOG_DIR}/${domain}-access.log combined
</VirtualHost>
EOF
        a2ensite "${domain}-ssl.conf" >/dev/null 2>&1 || true
    fi
    a2ensite "${domain}.conf" >/dev/null 2>&1 || true
    a2enmod rewrite ssl >/dev/null 2>&1 || true
    if apache2ctl configtest >/dev/null 2>&1; then
        systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1
        printf "    ${C_OK}✔${CR} ${C_DIM}Apache vhost written and reloaded${CR}\n"
    else
        printf "    ${C_WARN}!${CR} ${C_WARN}Apache config test failed - not reloading.${CR}\n"
    fi
    # The vhosts above carry no phpMyAdmin; put it back where its port says
    pma_sync_all
}


# Remove exactly one bot - its directory, its own database and DB user, its
# own Apache vhost files, its own SSL certificate - and leave MySQL, Apache
# itself, phpMyAdmin and every other bot on this server untouched. Used only
# when more than one bot is registered; a single-bot server keeps the old
# whole-server removal below.
remove_bot_scoped() {
    local name="$1" dir="$2" domain="$3"
    echo -e "\e[33mRemoving \"$name\" ($domain) only - other bots on this server are not touched.\033[0m"
    read -p "Are you sure? (y/n): " choice
    if [[ ! "$choice" =~ ^[Yy]$ ]]; then
        echo "Aborting..."
        return 0
    fi

    local cfg="$dir/config.php"
    local dbname dbuser dbhost
    if [ -f "$cfg" ]; then
        dbname=$(grep '^\$dbname' "$cfg" | cut -d"'" -f2)
        dbuser=$(grep '^\$usernamedb' "$cfg" | cut -d"'" -f2)
        dbhost=$(grep '^\$dbhost' "$cfg" | cut -d"'" -f2)
    fi
    [ -z "$dbhost" ] && dbhost="localhost"

    if [ -n "$dbname" ]; then
        local ROOT_PASS
        ROOT_PASS=$(grep '$pass' /root/confmirza/dbrootmirza.txt 2>/dev/null | cut -d"'" -f2)
        if [ -n "$ROOT_PASS" ]; then
            mysql -u root -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS \`$dbname\`;" 2>/dev/null \
                && echo -e "\e[92mDatabase removed: $dbname\033[0m" \
                || echo -e "\e[91mCould not drop database $dbname (continuing).\033[0m"
            if [ -n "$dbuser" ]; then
                mysql -u root -p"$ROOT_PASS" -e "DROP USER IF EXISTS '$dbuser'@'localhost'; DROP USER IF EXISTS '$dbuser'@'%';" 2>/dev/null
            fi
        else
            echo -e "\e[91mCould not read the MySQL root password - skipping database removal.\033[0m"
        fi
    fi

    if [ -n "$domain" ] && [ "$domain" != "null" ]; then
        a2dissite "${domain}.conf" >/dev/null 2>&1
        a2dissite "${domain}-ssl.conf" >/dev/null 2>&1
        a2dissite "${domain}-pma.conf" >/dev/null 2>&1
        rm -f "/etc/apache2/sites-available/${domain}.conf" "/etc/apache2/sites-available/${domain}-ssl.conf" "/etc/apache2/sites-available/${domain}-pma.conf"
        rm -f "/etc/apache2/sites-enabled/${domain}.conf" "/etc/apache2/sites-enabled/${domain}-ssl.conf" "/etc/apache2/sites-enabled/${domain}-pma.conf"
        systemctl reload apache2 >/dev/null 2>&1
        command -v certbot >/dev/null 2>&1 && certbot delete --cert-name "$domain" --non-interactive >/dev/null 2>&1
        echo -e "\e[92mVirtual host and certificate for $domain removed.\033[0m"
    fi

    if [ -d "$dir" ]; then
        rm -rf "$dir" && echo -e "\e[92mBot directory removed: $dir\033[0m" \
            || echo -e "\e[91mFailed to remove $dir.\033[0m"
    fi

    bots_registry_remove_by_dir "$dir"
    # Its phpMyAdmin port may now be unused: drop that Listen
    pma_sync_all
    echo -e "\e[92m\"$name\" has been removed.\033[0m"
}
function remove_bot() {
    bots_registry_ensure
    local _bot_count; _bot_count=$(jq 'length' "$BOTS_REGISTRY" 2>/dev/null)
    if [ -n "$_bot_count" ] && [ "$_bot_count" -gt 1 ]; then
        clear 2>/dev/null || true
        banner
        if pick_bot_instance "remove"; then
            remove_bot_scoped "$PICKED_NAME" "$PICKED_DIR" "$PICKED_DOMAIN"
        fi
        echo ""
        printf "  ${C_PROMPT}❯${CR} Press Enter to return to the menu... "
        read -r _
        show_menu
        return 0
    fi

    echo -e "\e[33mStarting Mirza Bot removal process...\033[0m"
    LOG_FILE="/var/log/remove_bot.log"
    echo "Log file: $LOG_FILE" > "$LOG_FILE"
    BOT_DIR="/var/www/html/mirzaprobotconfig"
    if [ ! -d "$BOT_DIR" ]; then
        echo -e "\e[31m[ERROR]\033[0m Mirza Bot is not installed (/var/www/html/mirzaprobotconfig not found)." | tee -a "$LOG_FILE"
        echo -e "\e[33mNothing to remove. Exiting...\033[0m" | tee -a "$LOG_FILE"
        sleep 2
        exit 1
    fi
    read -p "Are you sure you want to remove Mirza Bot and its dependencies? (y/n): " choice
    if [[ ! "$choice" =~ ^[Yy]$ ]]; then
        echo "Aborting..." | tee -a "$LOG_FILE"
        exit 0
    fi
    echo "Removing Mirza Bot..." | tee -a "$LOG_FILE"
    CONFIG_PATH="/var/www/html/mirzaprobotconfig/config.php"
    if [ -f "$CONFIG_PATH" ]; then
        sudo shred -u -n 5 "$CONFIG_PATH" && echo -e "\e[92mConfig file securely removed: $CONFIG_PATH\033[0m" | tee -a "$LOG_FILE" || {
            echo -e "\e[91mFailed to securely remove config file: $CONFIG_PATH\033[0m" | tee -a "$LOG_FILE"
        }
    fi
    if [ -d "$BOT_DIR" ]; then
        sudo rm -rf "$BOT_DIR" && echo -e "\e[92mBot directory removed: $BOT_DIR\033[0m" | tee -a "$LOG_FILE" || {
            echo -e "\e[91mFailed to remove bot directory: $BOT_DIR. Exiting...\033[0m" | tee -a "$LOG_FILE"
            exit 1
        }
    fi
    echo -e "\e[33mRemoving MySQL and database...\033[0m" | tee -a "$LOG_FILE"
    sudo systemctl stop mysql
    sudo systemctl disable mysql
    sudo systemctl daemon-reload
    sudo apt --fix-broken install -y
    sudo apt-get purge -y mysql-server mysql-client mysql-common mysql-server-core-* mysql-client-core-*
    sudo rm -rf /etc/mysql /var/lib/mysql /var/log/mysql /var/log/mysql.* /usr/lib/mysql /usr/include/mysql /usr/share/mysql
    sudo rm /lib/systemd/system/mysql.service
    sudo rm /etc/init.d/mysql
    sudo dpkg --remove --force-remove-reinstreq mysql-server mysql-server-8.0 mysql-server-8.4
    sudo find /etc/systemd /lib/systemd /usr/lib/systemd -name "*mysql*" -exec rm -f {} \;
    sudo apt-get purge -y 'mysql-server*' 'mysql-client*'
    sudo apt-get purge -y mysql-common php-mysql php8.2-mysql php8.3-mysql php8.4-mysql php-mariadb-mysql-kbs
    sudo apt-get autoremove --purge -y
    sudo apt-get clean
    sudo apt-get update --allow-releaseinfo-change
    echo -e "\e[92mMySQL has been completely removed.\033[0m" | tee -a "$LOG_FILE"
    echo -e "\e[33mRemoving PHPMyAdmin...\033[0m" | tee -a "$LOG_FILE"
    if dpkg -s phpmyadmin &>/dev/null; then
        sudo apt-get purge -y phpmyadmin && echo -e "\e[92mPHPMyAdmin removed.\033[0m" | tee -a "$LOG_FILE"
        sudo apt-get autoremove -y && sudo apt-get autoclean -y
    else
        echo -e "\e[93mPHPMyAdmin is not installed.\033[0m" | tee -a "$LOG_FILE"
    fi
    echo -e "\e[33mRemoving Apache...\033[0m" | tee -a "$LOG_FILE"
    sudo systemctl stop apache2 || {
        echo -e "\e[91mFailed to stop Apache. Continuing anyway...\033[0m" | tee -a "$LOG_FILE"
    }
    sudo systemctl disable apache2 || {
        echo -e "\e[91mFailed to disable Apache. Continuing anyway...\033[0m" | tee -a "$LOG_FILE"
    }
    sudo apt-get purge -y apache2 apache2-utils apache2-bin apache2-data libapache2-mod-php* || {
        echo -e "\e[91mFailed to purge Apache packages.\033[0m" | tee -a "$LOG_FILE"
    }
    sudo apt-get autoremove --purge -y
    sudo apt-get autoclean -y
    sudo rm -rf /etc/apache2 /var/www/html
    echo -e "\e[33mRemoving Apache and PHP configurations...\033[0m" | tee -a "$LOG_FILE"
    sudo a2disconf phpmyadmin.conf &>/dev/null
    sudo rm -f /etc/apache2/conf-available/phpmyadmin.conf
    echo -e "\e[33mRemoving additional packages...\033[0m" | tee -a "$LOG_FILE"
    sudo apt-get remove -y php-soap php-ssh2 libssh2-1-dev libssh2-1 \
        && echo -e "\e[92mRemoved additional PHP packages.\033[0m" | tee -a "$LOG_FILE" || echo -e "\e[93mSome additional PHP packages may not be installed.\033[0m" | tee -a "$LOG_FILE"
    echo -e "\e[33mResetting firewall rules (except SSL)...\033[0m" | tee -a "$LOG_FILE"
    sudo ufw delete allow 'Apache' 2>/dev/null
    sudo ufw reload 2>/dev/null
    # Clear Mirza install state so a fresh install is allowed afterwards
    sudo rm -rf /root/confmirza
    echo -e "\e[92mMirza Bot, MySQL, and their dependencies have been completely removed.\033[0m" | tee -a "$LOG_FILE"
}

function migrate_to_pro() {
    clear 2>/dev/null || true
    echo -e "\033[1;33mStarting Migration to Mirza Pro Max...\033[0m"
    if ! ensure_connectivity; then
        echo -e "  ${C_BAD}●${CR} ${C_BAD}No internet connection (even after DNS reset). Aborting.${CR}"
        sleep 2; show_menu; return 1
    fi
    OLD_BOT_DIR=""
    for _cand in "/var/www/html/mirzabotconfig" "/var/www/html/mirzaprobotconfig"; do
        [ -f "$_cand/config.php" ] && { OLD_BOT_DIR="$_cand"; break; }
    done
    if [ -z "$OLD_BOT_DIR" ]; then
        echo -e "\033[31m[ERROR] No existing Mirza install found (checked mirzabotconfig and mirzaprobotconfig).\033[0m"
        exit 1
    fi
    if [ -f "$OLD_BOT_DIR/version" ]; then
        echo -e "\033[33mThis server already runs Mirza Pro Max ($(cat "$OLD_BOT_DIR/version" 2>/dev/null)). Nothing to migrate - use option 2 (Update) instead.\033[0m"
        exit 0
    fi
    if ! systemctl is-active --quiet mysql; then
        echo -e "\033[31m[ERROR] MySQL service is not active or not installed.\033[0m"
        echo -e "\033[33mPlease ensure MySQL is running locally.\033[0m"
        exit 1
    else
        echo -e "\033[32mMySQL is running.\033[0m"
    fi
    echo ""
    read -p "Are you sure you want to migrate this bot to Mirza Pro Max? (y/n): " confirm_mig
    if [[ "$confirm_mig" != "y" && "$confirm_mig" != "Y" ]]; then
        echo -e "\033[31mMigration aborted.\033[0m"
        exit 0
    fi
    echo ""
    read -p "Have you created a backup of your database? (y/n): " confirm_backup
    if [[ "$confirm_backup" != "y" && "$confirm_backup" != "Y" ]]; then
        echo -e "\033[31mPlease create a backup first!\033[0m"
        exit 1
    fi
    BACKUP_FILE=$(ls -t /root/mirza_backup_*.sql /root/mirzabot_backup.sql 2>/dev/null | head -1)
    if [ -z "$BACKUP_FILE" ] || [ ! -s "$BACKUP_FILE" ]; then
        echo -e "\033[31m[ERROR] No database backup found in /root.\033[0m"
        echo -e "\033[33mRun 'mirza' and use option 6 (Backup Database) first, then try again.\033[0m"
        exit 1
    else
        echo -e "\033[32mBackup file found: $BACKUP_FILE\033[0m"
    fi
    echo ""
    echo -e "\033[43;30m[WARNING] Additional Bots Notice\033[0m"
    echo -e "\033[33mThis migration process will reconfigure Apache for the Pro version.\033[0m"
    echo -e "\033[33mOnly the main bot ($(basename "$OLD_BOT_DIR")) will be migrated.\033[0m"
    echo -e "\033[33mExisting Additional Bots in /var/www/html/ might stop working.\033[0m"
    echo -e "\033[36mFound directories:\033[0m"
    ls -d /var/www/html/*/ 2>/dev/null | grep -v "$(basename "$OLD_BOT_DIR")"
    echo ""
    read -p "Do you understand and want to proceed? (y/n): " confirm_add
    if [[ "$confirm_add" != "y" && "$confirm_add" != "Y" ]]; then
        echo -e "\033[31mMigration aborted.\033[0m"
        exit 0
    fi
    echo -e "\n\033[36mChecking Database Credentials...\033[0m"
    ROOT_CRED_FILE="/root/confmirza/dbrootmirza.txt"
    ROOT_PASS=""
    ROOT_USER="root"
    if [ -f "$ROOT_CRED_FILE" ]; then
        ROOT_PASS=$(grep '$pass' "$ROOT_CRED_FILE" | cut -d"'" -f2)
    fi
    if [ -z "$ROOT_PASS" ]; then
        echo -e "\033[33mRoot password not found in config file.\033[0m"
        read -s -p "Please enter MySQL root password: " ROOT_PASS
        echo ""
    fi
    if ! mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "SELECT 1;" &>/dev/null; then
        echo -e "\033[31m[ERROR] Incorrect MySQL root password. Migration stopped.\033[0m"
        exit 1
    fi
    echo -e "\033[32mDatabase connection successful.\033[0m"
    OLD_DB=$(grep '^\$dbname' "$OLD_BOT_DIR/config.php" | cut -d"'" -f2)
    NEW_DB="mirzaprobot"
    if [ -z "$OLD_DB" ]; then
        echo -e "\033[31m[ERROR] Could not read the database name from $OLD_BOT_DIR/config.php.\033[0m"
        exit 1
    fi
    if [ "$OLD_DB" = "$NEW_DB" ]; then
        echo -e "\033[33mThis install already uses the database Pro Max uses ($NEW_DB). Nothing to migrate - use option 2 (Update) instead.\033[0m"
        exit 0
    fi
    if ! mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "USE $OLD_DB;" &>/dev/null; then
        echo -e "\033[31m[ERROR] Database '$OLD_DB' not found!\033[0m"
        exit 1
    fi
    echo -e "\033[33mCleaning up old tables (setting, admin, channels)...\033[0m"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" "$OLD_DB" -e "DROP TABLE IF EXISTS setting, admin, channels;"
    echo -e "\033[33mUpdating panel status...\033[0m"
    if mysql -u "$ROOT_USER" -p"$ROOT_PASS" "$OLD_DB" -e "DESCRIBE marzban_panel;" &>/dev/null; then
         mysql -u "$ROOT_USER" -p"$ROOT_PASS" "$OLD_DB" -e "UPDATE marzban_panel SET status = 'active';"
    fi
    echo -e "\033[33mMigrating Database from $OLD_DB to $NEW_DB...\033[0m"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "CREATE DATABASE IF NOT EXISTS $NEW_DB;"
    TABLES=$(mysql -u "$ROOT_USER" -p"$ROOT_PASS" -N -e "SHOW TABLES FROM $OLD_DB")
    for t in $TABLES; do
        mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "RENAME TABLE $OLD_DB.$t TO $NEW_DB.$t"
    done
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS $OLD_DB;"
    echo -e "\033[32mDatabase migrated successfully.\033[0m"
    OLD_CONFIG="$OLD_BOT_DIR/config.php"
    OLD_DB_USER=$(grep '$usernamedb' "$OLD_CONFIG" | cut -d"'" -f2)
    if [ -n "$OLD_DB_USER" ]; then
        echo -e "\033[33mRemoving old database user ($OLD_DB_USER)...\033[0m"
        mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "DROP USER IF EXISTS '$OLD_DB_USER'@'localhost';"
        mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "DROP USER IF EXISTS '$OLD_DB_USER'@'%';"
    fi
    NEW_DB_USER=$(openssl rand -base64 10 | tr -dc 'a-zA-Z' | cut -c1-8)
    NEW_DB_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-10)
    echo -e "\033[33mCreating new database user...\033[0m"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "CREATE USER '$NEW_DB_USER'@'localhost' IDENTIFIED WITH mysql_native_password BY '$NEW_DB_PASS';"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "GRANT ALL PRIVILEGES ON $NEW_DB.* TO '$NEW_DB_USER'@'localhost';"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "CREATE USER '$NEW_DB_USER'@'%' IDENTIFIED WITH mysql_native_password BY '$NEW_DB_PASS';"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "GRANT ALL PRIVILEGES ON $NEW_DB.* TO '$NEW_DB_USER'@'%';"
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" -e "FLUSH PRIVILEGES;"
    echo -e "\033[33mReading old configuration...\033[0m"
    OLD_API_KEY=$(grep '$APIKEY' "$OLD_CONFIG" | cut -d"'" -f2)
    OLD_ADMIN_ID=$(grep '$adminnumber' "$OLD_CONFIG" | cut -d"'" -f2)
    OLD_BOT_NAME=$(grep '$usernamebot' "$OLD_CONFIG" | cut -d"'" -f2)
    OLD_DOMAIN_FULL=$(grep '$domainhosts' "$OLD_CONFIG" | cut -d"'" -f2)
    DOMAIN_NAME=$(echo "$OLD_DOMAIN_FULL" | cut -d'/' -f1)
    echo -e "\033[32mDomain detected: $DOMAIN_NAME\033[0m"
    NEW_BOT_DIR="/var/www/html/mirzaprobotconfig"
    rm -rf "$OLD_BOT_DIR"
    mkdir -p "$NEW_BOT_DIR"
    ZIP_URL="https://github.com/Alfred-1313/Mirza_Pro_Max/archive/refs/heads/master.zip"
    TEMP_DIR="/tmp/mirzabot_mig"
    mkdir -p "$TEMP_DIR"
    run_step "Downloading Mirza source" "wget -q -O '$TEMP_DIR/bot.zip' '$ZIP_URL'" \
        || { show_step_error; echo -e "\033[31mError: Failed to download Mirza source.\033[0m"; exit 1; }
    run_step "Extracting source files" "unzip -o -q '$TEMP_DIR/bot.zip' -d '$TEMP_DIR'" \
        || { show_step_error; echo -e "\033[31mError: Failed to extract source files.\033[0m"; exit 1; }
    EXTRACTED_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
        echo -e "\033[31mError: Extracted source folder not found. Aborting migration.\033[0m"
        rm -rf "$TEMP_DIR"; exit 1
    fi
    mv "$EXTRACTED_DIR"/* "$NEW_BOT_DIR"
    rm -rf "$TEMP_DIR"
    NEW_SECRET_TOKEN=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-8)
    cat <<EOF > "$NEW_BOT_DIR/config.php"
<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
\$request_exec_timeout = null;
\$dbhost = 'localhost';
\$dbname = '$NEW_DB';
\$usernamedb = '$NEW_DB_USER';
\$passworddb = '$NEW_DB_PASS';
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (\$connect->connect_error) { die("error" . \$connect->connect_error); }
mysqli_set_charset(\$connect, "utf8mb4");
\$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (\PDOException \$e) { error_log("Database connection failed: " . \$e->getMessage()); }
\$APIKEY = '${OLD_API_KEY}';
\$adminnumber = '${OLD_ADMIN_ID}';
\$domainhosts = '${DOMAIN_NAME}';
\$usernamebot = '${OLD_BOT_NAME}';
?>
EOF
    chown -R www-data:www-data "$NEW_BOT_DIR"
    chmod -R 755 "$NEW_BOT_DIR"
    run_step "Installing PHP dependencies (composer)" "install_php_deps '$NEW_BOT_DIR'" \
        || { show_step_error; echo -e "\033[31mError: Failed to install PHP dependencies.\033[0m"; exit 1; }
    echo -e "\033[33mReconfiguring Apache...\033[0m"
    a2dissite 000-default.conf 2>/dev/null || true
    a2dissite 000-default-le-ssl.conf 2>/dev/null || true
    rm -f /etc/apache2/sites-enabled/000-default* 2>/dev/null
    rm -f /etc/apache2/sites-available/000-default* 2>/dev/null
    VHOST_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}.conf"
    cat <<EOF > "$VHOST_FILE"
<VirtualHost *:80>
    ServerName $DOMAIN_NAME
    DocumentRoot $NEW_BOT_DIR
    <Directory $NEW_BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
    VHOST_SSL_FILE="/etc/apache2/sites-available/${DOMAIN_NAME}-ssl.conf"
    cat <<EOF > "$VHOST_SSL_FILE"
<VirtualHost *:443>
    ServerName $DOMAIN_NAME
    DocumentRoot $NEW_BOT_DIR
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem
    <Directory $NEW_BOT_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF
    a2ensite "${DOMAIN_NAME}.conf"
    a2ensite "${DOMAIN_NAME}-ssl.conf"
    a2enmod ssl
    a2enmod rewrite
    systemctl restart apache2
    echo -e "\033[33mUpdating database tables...\033[0m"
    curl -k "https://${DOMAIN_NAME}/table.php" > /dev/null 2>&1
    sleep 2
    echo -e "\033[33mUpdating webhook...\033[0m"
    curl -F "url=https://${DOMAIN_NAME}/index.php" \
         -F "secret_token=${NEW_SECRET_TOKEN}" \
         "https://api.telegram.org/bot${OLD_API_KEY}/setWebhook"
    sed -i 's/\r$//' /root/install.sh
    chmod +x /root/install.sh
    rm -f /usr/local/bin/mirza
    ln -sf /root/install.sh /usr/local/bin/mirza
    clear 2>/dev/null || true
    echo -e "\033[32m====================================================\033[0m"
    echo -e "\033[32m     MIGRATION TO MIRZA PRO MAX SUCCESSFUL         \033[0m"
    echo -e "\033[32m====================================================\033[0m"
    echo -e "\033[36mNew Database:\033[0m $NEW_DB"
    echo -e "\033[36mNew User:\033[0m     $NEW_DB_USER"
    echo -e "\033[36mNew Pass:\033[0m     $NEW_DB_PASS"
    echo -e "\033[36mBot Domain:\033[0m   https://$DOMAIN_NAME"
    echo -e "\033[33mUse command 'mirza' to manage the bot from now on.\033[0m"
    echo ""
}

# ── Command-line argument parsing ────────────────────────────
# Globals filled from flags (consumed by install/update where relevant)
ARG_NAME=""     ARG_TOKEN=""   ARG_ADMIN=""    ARG_DOMAIN=""
ARG_DBUSER=""   ARG_DBPASS=""

print_usage() {
    cat <<USAGE

  Mirza Pro Max - install and management script

  Usage:
    mirza                      open the interactive menu
    mirza <command> [options]

  Commands:
    install            Set up the bot on a clean server (Ubuntu/Debian)
    update             Fetch the newest code and migrate the database.
                       Your config.php, your database rows and any file the
                       project does not ship are kept. A rollback archive is
                       written to /root/mirza-backups/ before anything moves.
                       Works on an original Mirza install too - it upgrades
                       in place, it does not reinstall.
    remove             Delete the bot directory and the packages it installed
    migrate            Migrate an original Mirza install to Pro Max (beta)
    addbot             Install a second, independent bot on this server
    renew              Reissue the domain's SSL certificate
    backup             Dump the database and send it to Telegram
    import             Restore the database from a .sql or .zip backup (beta)
    menu               Open the interactive menu (default)

  Options:
    --name    <user>   Bot username, without the @
    --token   <token>  Telegram bot token from @BotFather
    --admin   <id>     Your numeric Telegram id (from @userinfobot)
    --domain  <fqdn>   Domain already pointed at this server, e.g. bot.example.com
    --db-user <user>   Database user to create
    --db-pass <pass>   Database password (letters, digits, underscore; 6-64)
    -h, --help         Show this help

  Examples:
    mirza install
    mirza install --name myvpnbot --token 123456:ABC --admin 111222333 \\
                  --domain bot.example.com --db-user mirza --db-pass s3cret_1
    mirza update

  Notes:
    - Run as root.
    - The domain must already resolve to this server before installing;
      the certificate step needs it.
    - Update never drops a database column or table: it only adds what is
      missing, so upgrading keeps every existing user, order and setting.

USAGE
}

process_arguments() {
    local cmd="menu"
    # First non-flag token is the command
    case "$1" in
        install|update|remove|migrate|renew|backup|import|addbot|menu|selfupdate-watch) cmd="$1"; shift ;;
        -h|--help) print_usage; exit 0 ;;
        "") cmd="menu" ;;
        --*) cmd="menu" ;;            # only flags given -> menu, but still parse flags
        *) cmd="menu" ;;
    esac

    # Parse remaining flags
    while [ $# -gt 0 ]; do
        case "$1" in
            --name)    ARG_NAME="$2";    shift 2 ;;
            --token)   ARG_TOKEN="$2";   shift 2 ;;
            --admin)   ARG_ADMIN="$2";   shift 2 ;;
            --domain)  ARG_DOMAIN="$2";  shift 2 ;;
            --db-user) ARG_DBUSER="$2";  shift 2 ;;
            --db-pass) ARG_DBPASS="$2";  shift 2 ;;
            -h|--help) print_usage; exit 0 ;;
            *) echo -e "\e[91mUnknown option: $1\033[0m"; print_usage; exit 1 ;;
        esac
    done

    case "$cmd" in
        install) install_bot ;;
        update)  update_bot ;;
        # internal: root's crontab, not something an admin types
        selfupdate-watch) selfupdate_watch ;;
        remove)  remove_bot ;;
        migrate) migrate_to_pro ;;
        renew)   renew_ssl ;;
        backup)  backup_bot ;;
        import)  import_bot ;;
        addbot)  install_second_bot ;;
        menu|*)  show_menu ;;
    esac
}
process_arguments "$@"