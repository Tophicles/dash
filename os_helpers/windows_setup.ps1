<#
.SYNOPSIS
    MultiDash Windows Server Setup Helper
    Sets up the 'mediasvc' user, SSH keys, and a restricted shell wrapper.
    Must be run as Administrator.

.DESCRIPTION
    1. Creates 'mediasvc' user.
    2. Adds user to 'Administrators' group.
    3. Configures SSH public key authentication.
    4. Installs a restricted shell wrapper script.
    5. Configures sshd_config to ForceCommand the wrapper.

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
$WrapperDir = "$env:ProgramData\MultiDash"
$WrapperFile = "$WrapperDir\ssh_wrapper.ps1"
$SSHDConfig = "$env:ProgramData\ssh\sshd_config"
$MarkerStart = "# BEGIN MEDIASVC-MULTIDASH"
$MarkerEnd = "# END MEDIASVC-MULTIDASH"

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

    $content = ""
    if (Test-Path $keyFile) {
        $content = Get-Content $keyFile -Raw
    }

    if ($content -notlike "*$Key*") {
        Add-Content -Path $keyFile -Value $Key -Encoding ASCII
    }

    # Permissions
    Write-Host "Setting key permissions..."
    $acl = Get-Acl $keyFile
    $acl.SetAccessRuleProtection($true, $false)
    $adminRule = New-Object System.Security.AccessControl.FileSystemAccessRule("Administrators", "FullControl", "Allow")
    $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule("SYSTEM", "FullControl", "Allow")
    $acl.SetAccessRule($adminRule)
    $acl.AddAccessRule($systemRule)
    Set-Acl -Path $keyFile -AclObject $acl

    # 4. Install Wrapper
    Write-Host "Installing Restricted Shell Wrapper..."
    if (-not (Test-Path $WrapperDir)) {
        New-Item -Path $WrapperDir -ItemType Directory -Force | Out-Null
    }

    $wrapperContent = @'
# MultiDash Restricted Shell Wrapper
$cmd = $env:SSH_ORIGINAL_COMMAND

if ([string]::IsNullOrWhiteSpace($cmd)) {
    Write-Error "Access Denied: No command provided."
    exit 1
}

# Strict parsing: ACTION "TARGET"
# Matches: MULTIDASH_COMMAND RESTART "Plex Media Server"
if ($cmd -match '^MULTIDASH_COMMAND (\w+) "([^"]+)"$') {
    $action = $matches[1].ToUpper()
    $target = $matches[2]

    # Sanity check target (prevent some injections although regex "([^"]+)" handles most)
    if ($target -match '[;&|]') {
        Write-Error "Invalid target characters."
        exit 1
    }

    switch ($action) {
        "START" {
            Start-Service -Name $target -ErrorAction Stop
            Write-Output "Service started"
        }
        "STOP" {
            Stop-Service -Name $target -Force -ErrorAction Stop
            Write-Output "Service stopped"
        }
        "RESTART" {
            Restart-Service -Name $target -Force -ErrorAction Stop
            Write-Output "Service restarted"
        }
        "STATUS" {
            $proc = Get-Process -Name $target -ErrorAction SilentlyContinue
            if ($proc) { Write-Output "active" } else { Write-Output "inactive" }
        }
        "START_PROCESS" {
            if (Test-Path $target) {
                Start-Process -FilePath $target
                Write-Output "Process started"
            } else {
                Write-Error "Executable not found: $target"
                exit 1
            }
        }
        "STOP_PROCESS" {
            $proc = Get-Process | Where-Object { $_.MainModule.FileName -eq $target }
            if ($proc) {
                Stop-Process -InputObject $proc -Force
                Write-Output "Process stopped"
            } else {
                Write-Output "Process not running"
            }
        }
        "RESTART_PROCESS" {
            $proc = Get-Process | Where-Object { $_.MainModule.FileName -eq $target }
            if ($proc) {
                Stop-Process -InputObject $proc -Force
            }
            Start-Sleep 2
            if (Test-Path $target) {
                Start-Process -FilePath $target
                Write-Output "Process restarted"
            } else {
                Write-Error "Executable not found: $target"
                exit 1
            }
        }
        "FIND_PATH" {
            $proc = Get-Process -Name $target -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($proc) {
                Write-Output $proc.MainModule.FileName
            } else {
                Write-Error "Process not found (is it running?)"
                exit 1
            }
        }
        "STATS" {
            # Windows Stats Generation
            Write-Output "OS: Windows"; Write-Output "---"

            # Uptime
            $boot = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
            $uptime = (Get-Date) - $boot
            Write-Output $uptime.TotalSeconds; Write-Output "---"

            # CPU
            $cpu = Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average
            Write-Output $cpu.Average; Write-Output "---"

            # Memory
            $os = Get-CimInstance Win32_OperatingSystem
            $total = $os.TotalVisibleMemorySize * 1024
            $free = $os.FreePhysicalMemory * 1024
            $used = $total - $free
            Write-Output "$used $total"; Write-Output "---"

            # Network
            $net = Get-CimInstance Win32_PerfFormattedData_Tcpip_NetworkInterface
            $rx = ($net | Measure-Object -Property BytesReceivedPerSec -Sum).Sum
            $tx = ($net | Measure-Object -Property BytesSentPerSec -Sum).Sum
            Write-Output "$rx $tx"; Write-Output "---"

            # Process
            $proc = Get-Process -Name $target -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($proc) {
                $mem = $proc.WorkingSet
                $time = $proc.TotalProcessorTime.TotalSeconds
                $threads = $proc.Threads.Count
                Write-Output "$mem $time $threads"
            } else {
                Write-Output "0 0 0"
            }
        }
        Default {
            Write-Error "Unknown action: $action"
            exit 1
        }
    }
} else {
    Write-Error "Invalid command format or access denied."
    exit 1
}
'@
    Set-Content -Path $WrapperFile -Value $wrapperContent

    # 5. Configure sshd_config
    Write-Host "Locking down sshd_config..."

    if (Test-Path $SSHDConfig) {
        $configContent = Get-Content $SSHDConfig -Raw

        # Remove old block if exists (regex replace is tricky with multiline, split/filter is safer)
        if ($configContent -match $MarkerStart) {
            # Use regex to remove the block
            $configContent = [regex]::Replace($configContent, "(?ms)$MarkerStart.*?$MarkerEnd\r?\n?", "")
        }

        # Append new block
        $block = @"

$MarkerStart
Match User $UserName
    ForceCommand powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "$WrapperFile"
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
    GatewayPorts no
$MarkerEnd
"@
        $configContent = $configContent + $block
        Set-Content -Path $SSHDConfig -Value $configContent

        Write-Host "Restarting sshd..."
        Restart-Service sshd -Force
    } else {
        Write-Warning "sshd_config not found at $SSHDConfig. Manual configuration required."
    }

    Write-Host "✅ Setup complete. 'mediasvc' is ready and locked down." -ForegroundColor Green
}

function Uninstall-User {
    Write-Host "--- MultiDash Windows Uninstall ---" -ForegroundColor Yellow

    # Clean sshd_config
    if (Test-Path $SSHDConfig) {
        $content = Get-Content $SSHDConfig -Raw
        if ($content -match $MarkerStart) {
            Write-Host "Removing config block from sshd_config..."
            $newContent = [regex]::Replace($content, "(?ms)$MarkerStart.*?$MarkerEnd\r?\n?", "")
            Set-Content -Path $SSHDConfig -Value $newContent
            Restart-Service sshd -Force -ErrorAction SilentlyContinue
        }
    }

    # Remove Wrapper
    if (Test-Path $WrapperDir) {
        Remove-Item -Path $WrapperDir -Recurse -Force -ErrorAction SilentlyContinue
    }

    # Warning about Key
    $sshDir = "$env:ProgramData\ssh"
    $keyFile = "$sshDir\administrators_authorized_keys"
    if (Test-Path $keyFile) {
        Write-Warning "Please manually remove the key from $keyFile to fully revoke access."
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
