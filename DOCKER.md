# MultiDash Docker Guide

MultiDash is designed to be **dual-purpose**, supporting both standard Docker environments (via Docker Compose) and unRAID (via XML templates) using the same codebase.

## Quick Start (Docker Compose)

For standard Linux servers, Synology, or local development:

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/Tophicles/dash.git
    cd dash
    ```

2.  **Start the container:**
    ```bash
    docker-compose up -d --build
    ```

3.  **Access the dashboard:**
    Open `http://localhost:8088`.

---

## unRAID Setup

You can install MultiDash on unRAID either by pulling a pre-built image (if you have pushed one) or by building it locally.

### Option 1: Docker Hub / GHCR (Pull via Interface)
*Use this method if you have built and pushed the image to a registry like Docker Hub or GitHub Container Registry (GHCR).*

1.  **Install on unRAID:**
    *   Go to the **Docker** tab and click **Add Container**.
    *   **Name:** `MultiDash`
    *   **Repository:** `your-dockerhub-user/dash` (or `ghcr.io/tophicles/dash` if configured).
    *   **WebUI:** `http://[IP]:[PORT:80]`
    *   **Network Type:** `Bridge`
    *   **Port Mapping:** Container `80` -> Host `8088`.
    *   **Path Mapping:** Container `/config` -> Host `/mnt/user/appdata/multidash`.
    *   **Variables:** Add `PUID` (99) and `PGID` (100).
    *   Click **Apply**.

### Option 2: Local Build (Recommended for Private Use)
*Use this method to build the image directly on your unRAID server without needing an external registry.*

1.  **Build locally:**
    Open the unRAID Web Terminal:
    ```bash
    # Clone the repo (using the correct URL)
    git clone https://github.com/Tophicles/dash.git /tmp/dash

    # Build the image with the tag 'multidash'
    docker build -t multidash /tmp/dash

    # Cleanup
    rm -rf /tmp/dash
    ```

2.  **Install:**
    *   Go to **Add Container**.
    *   **Repository:** `multidash` (This matches the tag you just built).
    *   Configure Ports, Paths, and Variables as shown in Option 1.

### Option 3: XML Template
If you prefer using a template, copy `multidash-unraid.xml` to `/boot/config/plugins/dockerMan/templates-user/my-multidash.xml` on your unRAID USB drive. It will then appear in the "Templates" dropdown when adding a container.

---

## Configuration

The container uses a **volume** mapped to `/config` to store all persistent data (`users.json`, `servers.json`, logs, and keys).

**Environment Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `PUID`   | `33`    | User ID. Set to `99` for unRAID or `1000` for standard Linux to match host permissions. |
| `PGID`   | `33`    | Group ID. Set to `100` for unRAID or `1000` for standard Linux. |
