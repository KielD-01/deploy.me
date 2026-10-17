<#
.SYNOPSIS
    Installs deploy.me on Windows, inside WSL2.

.DESCRIPTION
    deploy.me runs in Docker and drives ssh, rsync and git the Linux way, so on
    Windows it lives in a WSL2 distribution, with Docker Desktop's WSL
    integration. This script checks both, clones the repository into the
    distribution's home folder (not the Windows drive: that is slow and loses
    file modes) and runs bin/install there, which does the rest and trusts the
    local CA in Windows too, for Edge and Chrome.

    Run it from PowerShell (no admin rights needed):

        irm https://raw.githubusercontent.com/KielD-01/deploy.me/main/bin/install.ps1 | iex

    or, from a copy of the repository:

        powershell -ExecutionPolicy Bypass -File bin\install.ps1

.PARAMETER Distro
    The WSL distribution to use. Default: the default distribution.

.PARAMETER Stack
    shared or standalone. Default: whatever bin/configure detects (standalone,
    unless a shared stack is already running).
#>
param(
    [string] $Distro = "",
    [ValidateSet("", "shared", "standalone")] [string] $Stack = "",
    [string] $Repository = "https://github.com/KielD-01/deploy.me.git",
    [string] $Folder = "deploy.me"
)

$ErrorActionPreference = "Stop"

function Say($text) { Write-Host "==> $text" -ForegroundColor Cyan }
function Ok($text) { Write-Host "  [ok] $text" -ForegroundColor Green }
function Fail($text) { Write-Host "  [x] $text" -ForegroundColor Red; exit 1 }

Say "Installing deploy.me on Windows (through WSL2)"

# --- WSL2 -------------------------------------------------------------------
if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
    Fail "WSL isn't installed. In an admin PowerShell run: wsl --install   then restart and run this again."
}

$distros = (wsl.exe --list --quiet 2>$null) -replace "`0", "" | Where-Object { $_.Trim() -ne "" }
if (-not $distros) {
    Fail "WSL has no Linux distribution yet. Run: wsl --install -d Ubuntu   then open Ubuntu once to create your user."
}
$wsl = @()
if ($Distro -ne "") {
    if (-not ($distros -contains $Distro)) { Fail "No WSL distribution named $Distro. You have: $($distros -join ', ')" }
    $wsl = @("-d", $Distro)
}

$version = wsl.exe @wsl -e sh -c "uname -r"
if ($version -notmatch "WSL2|microsoft-standard") {
    Fail "That distribution runs under WSL 1. Convert it: wsl --set-version <name> 2"
}
Ok "WSL2 ($($version.Trim()))"

# --- Docker Desktop with WSL integration ------------------------------------
wsl.exe @wsl -e sh -c "docker info >/dev/null 2>&1"
if ($LASTEXITCODE -ne 0) {
    Fail "Docker isn't reachable inside WSL. Start Docker Desktop, then Settings > Resources > WSL integration: turn it on for this distribution."
}
Ok "Docker Desktop answers inside WSL"

# --- The tools bin/install needs inside the distribution ---------------------
wsl.exe @wsl -e sh -c "command -v git >/dev/null && command -v make >/dev/null && command -v openssl >/dev/null && command -v curl >/dev/null"
if ($LASTEXITCODE -ne 0) {
    Say "Installing git, make, openssl and curl in WSL (asks for your Linux password)"
    wsl.exe @wsl -e sh -c "sudo apt-get update -qq && sudo apt-get install -y -qq git make openssl curl libnss3-tools"
    if ($LASTEXITCODE -ne 0) { Fail "Couldn't install them. Install git, make, openssl and curl in your distribution, then run this again." }
}

# --- Clone into the WSL home, then hand over -------------------------------
$clone = "cd ~ && if [ -d '$Folder/.git' ]; then cd '$Folder' && git pull --ff-only; else git clone '$Repository' '$Folder' && cd '$Folder'; fi"
$installArgs = ""
if ($Stack -ne "") { $installArgs = "--stack $Stack" }

Say "Running bin/install inside WSL (~/$Folder)"
wsl.exe @wsl -e bash -lc "$clone && bin/install $installArgs"
if ($LASTEXITCODE -ne 0) { Fail "bin/install stopped. Fix what it says, then run: wsl -e bash -lc 'cd ~/$Folder && bin/install'" }

Ok "Done. Open https://deployme.localhost in your browser."
Write-Host "  Day to day, run make commands inside WSL: wsl -e bash -lc 'cd ~/$Folder && make help'"
