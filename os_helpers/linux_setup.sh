#!/bin/bash
set -euo pipefail

########################################
# MultiDash - Linux Server Setup Helper
# Supports systemd-based distros only
########################################

USER_NAME="mediasvc"
SUDOERS_FILE="/etc/sudoers.d/$USER_NAME"
SSHD_CONFIG="/etc/ssh/sshd_config"
MARKER_START="# BEGIN MEDIASVC-MULTIDASH"
MARKER_END="# END MEDIASVC-MULTIDASH"

########################################
# Root check
########################################
if [ "$EUID" -ne 0 ]; then
  echo "❌ Please run as root (use sudo)"
  exit 1
fi

########################################
# systemd check
########################################
if ! command -v systemctl >/dev/null; then
  echo "❌ systemd not detected. This installer supports systemd-based Linux only."
  exit 1
fi

########################################
# Command detection
########################################
SYSTEMCTL=$(command -v systemctl)
UPTIME=$(command -v uptime)
FREE=$(command -v free)

########################################
# User creation
########################################
create_user() {
    if id "$USER_NAME" &>/dev/null; then
        echo "User '$USER_NAME' already exists."
        return
    fi

    echo "Creating user '$USER_NAME'..."
    adduser --disabled-password --gecos "" "$USER_NAME" 2>/dev/null \
      || useradd -m -s /usr/sbin/nologin "$USER_NAME"
}

########################################
# Generate sudoers (CORRECT)
########################################
generate_sudoers() {
    rm -f "$SUDOERS_FILE"

    {
      echo "$USER_NAME ALL=(ALL) NOPASSWD: \\"
      echo "  $SYSTEMCTL start plexmediaserver, \\"
      echo "  $SYSTEMCTL stop plexmediaserver, \\"
      echo "  $SYSTEMCTL restart plexmediaserver, \\"
      echo "  $SYSTEMCTL is-active plexmediaserver, \\"
      echo "  $SYSTEMCTL show plexmediaserver -p MemoryCurrent -p CPUUsageNSec, \\"
      echo "  $SYSTEMCTL start emby-server, \\"
      echo "  $SYSTEMCTL stop emby-server, \\"
      echo "  $SYSTEMCTL restart emby-server, \\"
      echo "  $SYSTEMCTL is-active emby-server, \\"
      echo "  $SYSTEMCTL show emby-server -p MemoryCurrent -p CPUUsageNSec, \\"
      echo "  $SYSTEMCTL start jellyfin, \\"
      echo "  $SYSTEMCTL stop jellyfin, \\"
      echo "  $SYSTEMCTL restart jellyfin, \\"
      echo "  $SYSTEMCTL is-active jellyfin, \\"
      echo "  $SYSTEMCTL show jellyfin -p MemoryCurrent -p CPUUsageNSec, \\"
      echo "  $UPTIME, \\"
      echo "  $FREE -m"
    } > "$SUDOERS_FILE"

    chmod 440 "$SUDOERS_FILE"

    # Validate hard
    visudo -cf "$SUDOERS_FILE"
}

########################################
# Install
########################################
install_user() {
    echo "=========================================="
    echo "   MultiDash Linux Server Setup (Install) "
    echo "=========================================="

    echo "Paste SSH public key:"
    read -r PUB_KEY

    if [ -z "$PUB_KEY" ]; then
        echo "❌ Public key cannot be empty."
        exit 1
    fi

    create_user

    echo "Configuring SSH key..."
    SSH_DIR="/home/$USER_NAME/.ssh"
    mkdir -p "$SSH_DIR"
    echo "$PUB_KEY" > "$SSH_DIR/authorized_keys"
    chown -R "$USER_NAME:$USER_NAME" "$SSH_DIR"
    chmod 700 "$SSH_DIR"
    chmod 600 "$SSH_DIR/authorized_keys"

    echo "Configuring sudoers..."
    generate_sudoers

    echo "Locking down SSH..."
    sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"

    cat >> "$SSHD_CONFIG" <<EOF

$MARKER_START
Match User $USER_NAME
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
$MARKER_END
EOF

    if systemctl is-active --quiet ssh; then
        systemctl reload ssh
    elif systemctl is-active --quiet sshd; then
        systemctl reload sshd
    fi

    echo "✅ Setup complete"
}

########################################
# Uninstall
########################################
uninstall_user() {
    deluser --remove-home "$USER_NAME" 2>/dev/null || userdel -r "$USER_NAME"
    rm -f "$SUDOERS_FILE"
    sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"
    systemctl reload ssh 2>/dev/null || true
    echo "✅ Uninstall complete"
}

########################################
# Main
########################################
case "${1:-}" in
    install) install_user ;;
    uninstall) uninstall_user ;;
    *)
        echo "Usage: sudo ./linux_setup.sh [install|uninstall]"
        read -p "Choice [1-2]: " c
        [ "$c" = "1" ] && install_user
        [ "$c" = "2" ] && uninstall_user
        ;;
esac
