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
MARKER_END="# END MEDIDASH-MULTIDASH"

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
# Command detection (portable paths)
########################################
SYSTEMCTL=$(command -v systemctl)
UPTIME=$(command -v uptime)
FREE=$(command -v free)

########################################
# User creation helper
########################################
create_user() {
    if id "$USER_NAME" &>/dev/null; then
        echo "User '$USER_NAME' already exists."
        return
    fi

    echo "Creating user '$USER_NAME'..."

    if command -v adduser >/dev/null; then
        adduser --disabled-password --gecos "" "$USER_NAME"
    else
        useradd -m -s /usr/sbin/nologin "$USER_NAME"
    fi
}

########################################
# Generate sudoers safely
########################################
generate_sudoers() {
    # Remove old sudoers file if it exists
    if [ -f "$SUDOERS_FILE" ]; then
        rm -f "$SUDOERS_FILE"
    fi

    local commands=(
      "$SYSTEMCTL start plexmediaserver"
      "$SYSTEMCTL stop plexmediaserver"
      "$SYSTEMCTL restart plexmediaserver"
      "$SYSTEMCTL is-active plexmediaserver"
      "$SYSTEMCTL show plexmediaserver -p MemoryCurrent -p CPUUsageNSec"

      "$SYSTEMCTL start emby-server"
      "$SYSTEMCTL stop emby-server"
      "$SYSTEMCTL restart emby-server"
      "$SYSTEMCTL is-active emby-server"
      "$SYSTEMCTL show emby-server -p MemoryCurrent -p CPUUsageNSec"

      "$SYSTEMCTL start jellyfin"
      "$SYSTEMCTL stop jellyfin"
      "$SYSTEMCTL restart jellyfin"
      "$SYSTEMCTL is-active jellyfin"
      "$SYSTEMCTL show jellyfin -p MemoryCurrent -p CPUUsageNSec"

      "$UPTIME"
      "$FREE -m"
    )

    # Write sudoers file — all commands on one line, safe syntax
    {
      echo "$USER_NAME ALL=(ALL) NOPASSWD: ${commands[*]}"
    } > "$SUDOERS_FILE"

    chmod 440 "$SUDOERS_FILE"

    # Validate sudoers
    if ! visudo -cf "$SUDOERS_FILE" >/dev/null; then
        echo "❌ Generated sudoers file has syntax errors!"
        exit 1
    fi
}

########################################
# Install
########################################
install_user() {
    echo "=========================================="
    echo "   MultiDash Linux Server Setup (Install) "
    echo "=========================================="
    echo ""

    echo "Please paste the SSH Public Key generated in your MultiDash dashboard:"
    read -r PUB_KEY

    if [ -z "$PUB_KEY" ]; then
        echo "❌ Public key cannot be empty."
        exit 1
    fi

    create_user

    ########################################
    # SSH key setup
    ########################################
    echo "Configuring SSH key..."
    SSH_DIR="/home/$USER_NAME/.ssh"
    mkdir -p "$SSH_DIR"
    echo "$PUB_KEY" > "$SSH_DIR/authorized_keys"
    chown -R $USER_NAME:$USER_NAME "$SSH_DIR"
    chmod 700 "$SSH_DIR"
    chmod 600 "$SSH_DIR/authorized_keys"

    ########################################
    # Sudoers
    ########################################
    echo "Configuring sudoers restrictions..."
    generate_sudoers

    ########################################
    # SSHD lockdown
    ########################################
    echo "Configuring SSHD restrictions..."
    sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"

    cat >> "$SSHD_CONFIG" << EOF

$MARKER_START
Match User $USER_NAME
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
$MARKER_END
EOF

    ########################################
    # Reload SSH
    ########################################
    echo "Reloading SSH service..."
    if systemctl is-active --quiet ssh; then
        systemctl reload ssh
    elif systemctl is-active --quiet sshd; then
        systemctl reload sshd
    else
        echo "⚠️ Could not detect SSH service name. Reload manually if needed."
    fi

    echo ""
    echo "✅ Setup complete!"
    echo "Systemd detected, user locked down, safe for MultiDash."
}

########################################
# Uninstall
########################################
uninstall_user() {
    echo "=========================================="
    echo "  MultiDash Linux Server Setup (Uninstall)"
    echo "=========================================="
    echo ""

    if id "$USER_NAME" &>/dev/null; then
        echo "Removing user '$USER_NAME'..."
        deluser --remove-home "$USER_NAME" 2>/dev/null || userdel -r "$USER_NAME"
    else
        echo "User '$USER_NAME' not found."
    fi

    if [ -f "$SUDOERS_FILE" ]; then
        echo "Removing sudoers file..."
        rm -f "$SUDOERS_FILE"
    fi

    echo "Cleaning up SSHD config..."
    if grep -q "$MARKER_START" "$SSHD_CONFIG"; then
        sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"
        if systemctl is-active --quiet ssh; then
            systemctl reload ssh
        elif systemctl is-active --quiet sshd; then
            systemctl reload sshd
        fi
    fi

    echo ""
    echo "✅ Uninstall complete."
}

########################################
# Main
########################################
case "${1:-}" in
    install) install_user ;;
    uninstall) uninstall_user ;;
    *)
        echo "Usage: sudo ./linux_setup.sh [install|uninstall]"
        echo ""
        echo "1) Install (Create user & lock down)"
        echo "2) Uninstall (Remove user & clean up)"
        read -p "Choice [1-2]: " choice
        case "$choice" in
            1) install_user ;;
            2) uninstall_user ;;
            *) echo "Invalid choice"; exit 1 ;;
        esac
        ;;
esac
