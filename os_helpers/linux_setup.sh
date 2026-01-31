#!/bin/bash

# MultiDash - Linux Server Setup Helper
# This script automates the creation/removal of the restricted 'mediasvc' user
# for use with the MultiDash SSH Remote Control feature.

USER_NAME="mediasvc"
SUDOERS_FILE="/etc/sudoers.d/$USER_NAME"
SSHD_CONFIG="/etc/ssh/sshd_config"
MARKER_START="# BEGIN MEDIASVC-MULTIDASH"
MARKER_END="# END MEDIASVC-MULTIDASH"

# Check for root
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (use sudo)"
  exit 1
fi

function install_user() {
    echo "=========================================="
    echo "   MultiDash Linux Server Setup (Install) "
    echo "=========================================="
    echo ""

    # 1. Prompt for Key
    echo "Please paste the SSH Public Key generated in your MultiDash dashboard:"
    read -r PUB_KEY

    if [ -z "$PUB_KEY" ]; then
        echo "Error: Public key cannot be empty."
        exit 1
    fi

    # 2. Create User
    if id "$USER_NAME" &>/dev/null; then
        echo "User '$USER_NAME' already exists. Updating configuration..."
    else
        echo "Creating user '$USER_NAME'..."
        adduser --disabled-password --gecos "" $USER_NAME
    fi

    # 3. Setup SSH Key
    echo "Configuring SSH key..."
    SSH_DIR="/home/$USER_NAME/.ssh"
    mkdir -p "$SSH_DIR"
    echo "$PUB_KEY" > "$SSH_DIR/authorized_keys"
    chown -R $USER_NAME:$USER_NAME "$SSH_DIR"
    chmod 700 "$SSH_DIR"
    chmod 600 "$SSH_DIR/authorized_keys"

    # 4. Setup Sudoers
    echo "Configuring sudoers restrictions..."
    cat > "$SUDOERS_FILE" << EOF
$USER_NAME ALL=(ALL) NOPASSWD: \\
  /bin/systemctl start plexmediaserver, \\
  /bin/systemctl stop plexmediaserver, \\
  /bin/systemctl restart plexmediaserver, \\
  /bin/systemctl start emby-server, \\
  /bin/systemctl stop emby-server, \\
  /bin/systemctl restart emby-server, \\
  /bin/systemctl start jellyfin, \\
  /bin/systemctl stop jellyfin, \\
  /bin/systemctl restart jellyfin
EOF
    chmod 440 "$SUDOERS_FILE"

    # 5. Setup SSHD Config
    echo "Configuring SSHD restrictions..."
    # Remove old block if exists
    sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"

    # Append new block
    cat >> "$SSHD_CONFIG" << EOF

$MARKER_START
Match User $USER_NAME
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
$MARKER_END
EOF

    # 6. Restart SSH
    echo "Reloading SSH service..."
    if systemctl is-active --quiet ssh; then
        systemctl reload ssh
    elif systemctl is-active --quiet sshd; then
        systemctl reload sshd
    else
        echo "Warning: Could not detect ssh service name to reload. Please reload manually."
    fi

    echo ""
    echo "✅ Setup Complete!"
    echo "You can now configure your server in MultiDash using the 'Linux' OS type."
}

function uninstall_user() {
    echo "=========================================="
    echo "  MultiDash Linux Server Setup (Uninstall)"
    echo "=========================================="
    echo ""

    # 1. Remove User
    if id "$USER_NAME" &>/dev/null; then
        echo "Removing user '$USER_NAME'..."
        deluser --remove-home $USER_NAME
    else
        echo "User '$USER_NAME' not found."
    fi

    # 2. Remove Sudoers
    if [ -f "$SUDOERS_FILE" ]; then
        echo "Removing sudoers file..."
        rm "$SUDOERS_FILE"
    fi

    # 3. Remove SSHD Config Block
    echo "Cleaning up SSHD config..."
    if grep -q "$MARKER_START" "$SSHD_CONFIG"; then
        sed -i "/$MARKER_START/,/$MARKER_END/d" "$SSHD_CONFIG"
        # Reload SSH
        echo "Reloading SSH service..."
        if systemctl is-active --quiet ssh; then
            systemctl reload ssh
        elif systemctl is-active --quiet sshd; then
            systemctl reload sshd
        fi
    else
        echo "No configuration block found in $SSHD_CONFIG."
    fi

    echo ""
    echo "✅ Uninstall Complete!"
}

# Main Menu
if [ "$1" == "install" ]; then
    install_user
elif [ "$1" == "uninstall" ]; then
    uninstall_user
else
    echo "Usage: sudo ./linux_setup.sh [install|uninstall]"
    echo ""
    echo "Select an option:"
    echo "1) Install (Create user & lock down)"
    echo "2) Uninstall (Remove user & clean up)"
    read -p "Choice [1-2]: " choice
    case $choice in
        1) install_user ;;
        2) uninstall_user ;;
        *) echo "Invalid choice"; exit 1 ;;
    esac
fi
