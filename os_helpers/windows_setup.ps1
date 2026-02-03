<#
.SYNOPSIS
    MultiDash Windows Server Setup Helper
    Sets up the 'mediasvc' user and SSH keys for remote management.
    Must be run as Administrator.

.DESCRIPTION
    1. Creates 'mediasvc' user with a random password.
    2. Adds user to 'Administrators' group (required for service management).
    3. Configures SSH public key authentication in 'administrators_authorized_keys'.
    4. Sets correct ACLs for the key file.

.PARAMETER Install
    Switch to perform installation.
.PARAMETER Uninstall
    Switch to perform uninstallation.
.PARAMETER Key
    The SSH Public Key string (required for Install).
#>

param (
    [switch]$Install,
    [switch]$Uninstall,
    [string]$Key
)

$ErrorActionPreference = "Stop"
$UserName = "mediasvc"

# --- Helper Functions ---

function Test-IsAdmin {
    $currentPrincipal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    return $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-OpenSSHPath {
    $path = "$env:ProgramData\ssh"
    if (-not (Test-Path $path)) {
        Throw "OpenSSH Server does not appear to be installed or initialized. (Missing $path)"
    }
    return $path
}

# --- Actions ---

function Install-User {
    if (-not $Key) {
        Write-Error "Please provide the Public Key via the -Key parameter."
        exit 1
    }

    Write-Host "--- MultiDash Windows Setup ---" -ForegroundColor Cyan

    # 1. Create User
    $existingUser = Get-LocalUser -Name $UserName -ErrorAction SilentlyContinue
    if (-not $existingUser) {
        Write-Host "Creating user '$UserName'..."
        $password = -join ((33..126) | Get-Random -Count 32 | ForEach-Object {[char]$_})
        $securePassword = ConvertTo-SecureString $password -AsPlainText -Force
        New-LocalUser -Name $UserName -Password $securePassword -PasswordNeverExpires -Description "MultiDash Service Account" | Out-Null
    } else {
        Write-Host "User '$UserName' already exists."
    }

    # 2. Add to Admin
    Write-Host "Adding to Administrators group..."
    Add-LocalGroupMember -Group "Administrators" -Member $UserName -ErrorAction SilentlyContinue

    # 3. Setup SSH Key
    $sshDir = Get-OpenSSHPath
    $keyFile = "$sshDir\administrators_authorized_keys"

    Write-Host "Configuring SSH key..."

    # Check if key already exists in file
    $content = ""
    if (Test-Path $keyFile) {
        $content = Get-Content $keyFile -Raw
    }

    if ($content -notlike "*$Key*") {
        Add-Content -Path $keyFile -Value $Key -Encoding ASCII
    }

    # 4. Permissions (Critical for OpenSSH Admin Keys)
    # Permissions must be: SYSTEM:F, Administrators:F, Owner:F. No other access.
    Write-Host "Setting file permissions..."

    $acl = Get-Acl $keyFile
    $acl.SetAccessRuleProtection($true, $false) # Disable inheritance

    $adminRule = New-Object System.Security.AccessControl.FileSystemAccessRule("Administrators", "FullControl", "Allow")
    $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule("SYSTEM", "FullControl", "Allow")

    $acl.SetAccessRule($adminRule)
    $acl.AddAccessRule($systemRule)

    Set-Acl -Path $keyFile -AclObject $acl

    Write-Host "✅ Setup complete. 'mediasvc' is ready." -ForegroundColor Green
    Write-Host "Ensure the 'OpenSSH SSH Server' service is running."
}

function Uninstall-User {
    Write-Host "--- MultiDash Windows Uninstall ---" -ForegroundColor Yellow

    # Remove Key
    $sshDir = "$env:ProgramData\ssh"
    $keyFile = "$sshDir\administrators_authorized_keys"

    if (Test-Path $keyFile) {
        Write-Host "Removing key from administrators_authorized_keys..."
        # Note: This is a simple implementation that removes lines matching the user comment if possible,
        # but since we don't store the key, we can't selectively remove easily without potentially deleting others.
        # For safety in this script, we will warn.
        Write-Warning "Cannot automatically remove specific key from $keyFile without exact match."
        Write-Warning "Please manually edit $keyFile to remove the dashboard key."
    }

    # Remove User
    if (Get-LocalUser -Name $UserName -ErrorAction SilentlyContinue) {
        Write-Host "Removing user '$UserName'..."
        Remove-LocalUser -Name $UserName
    }

    Write-Host "✅ Uninstall complete." -ForegroundColor Green
}

# --- Main Entry ---

if (-not (Test-IsAdmin)) {
    Write-Error "This script must be run as Administrator."
    exit 1
}

if ($Install) {
    Install-User
} elseif ($Uninstall) {
    Uninstall-User
} else {
    Write-Host "Usage: .\windows_setup.ps1 -Install -Key 'ssh-rsa ...'"
    Write-Host "       .\windows_setup.ps1 -Uninstall"
}
