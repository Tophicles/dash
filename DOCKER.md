# MultiDash Docker Guide

MultiDash is designed to be **dual-purpose**, supporting both standard Docker environments (via Docker Compose) and unRAID (via XML templates) using the same codebase.

## Quick Start (Docker Compose)

For standard Linux servers, Synology, or local development:

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/yourusername/multidash.git
    cd multidash
    ```

2.  **Start the container:**
    ```bash
    docker-compose up -d --build
    ```

3.  **Access the dashboard:**
    Open `http://localhost:8088`.

---

## unRAID Setup

You can install MultiDash on unRAID either by pulling a pre-built image from Docker Hub (easiest) or by building it locally.

### Option 1: Docker Hub (Recommended)
*Use this method if you have pushed the image to your Docker Hub account.*

1.  **Push the image:**
    Build and push the image to Docker Hub from your computer:
    ```bash
    docker build -t yourusername/multidash .
    docker push yourusername/multidash
    ```

2.  **Install on unRAID:**
    *   Go to the **Docker** tab and click **Add Container**.
    *   **Name:** `MultiDash`
    *   **Repository:** `yourusername/multidash` (or just the URL if using a private registry).
    *   **WebUI:** `http://[IP]:[PORT:80]`
    *   **Network Type:** `Bridge`
    *   **Port Mapping:** Container `80` -> Host `8088`.
    *   **Path Mapping:** Container `/config` -> Host `/mnt/user/appdata/multidash`.
    *   **Variables:** Add `PUID` (99) and `PGID` (100).
    *   Click **Apply**.

### Option 2: Local Build (Development)
*Use this method if you want to run the code directly on unRAID without using Docker Hub.*

1.  **Build locally:**
    Open the unRAID Web Terminal, clone the repo to a temp folder, and build:
    ```bash
    git clone https://github.com/yourusername/multidash.git /tmp/multidash
    docker build -t multidash /tmp/multidash
    rm -rf /tmp/multidash
    ```

2.  **Install:**
    *   Go to **Add Container**.
    *   **Repository:** `multidash` (Local image name).
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
