# MultiDash Docker Guide

MultiDash is fully compatible with Docker and can be deployed on standard Linux servers, unRAID, Synology, or any system supporting Docker.

## Quick Start (Docker Compose)

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/yourusername/multidash.git
    cd multidash
    ```

2.  **Start the container:**
    ```bash
    docker-compose up -d --build
    ```
    *The `--build` flag ensures the image is created from the source code.*

3.  **Access the dashboard:**
    Open your browser to `http://localhost:8088`.

## Persistence & Configuration

The container uses a **volume** mapped to `/config` to store all persistent data. This includes:
*   `users.json` (User accounts)
*   `servers.json` (Server configuration)
*   `dashboard.log` (System logs)
*   `key.php` (Encryption key)
*   `keys/` (Generated SSH keys)

**Important:** Always ensure the `/config` volume is mapped to a persistent directory on your host. If you delete the container without this mapping, your configuration will be lost.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PUID`   | `33`    | User ID to run the application as. Set this to match your host user's UID (e.g., 1000 on Linux, 99 on unRAID) to prevent permission issues. |
| `PGID`   | `33`    | Group ID to run the application as. |

---

## unRAID Setup

Since this container is not yet hosted on Docker Hub, you will need to build the image locally on your unRAID server.

### Step 1: Build the Image
1.  Open the unRAID Web Terminal.
2.  Clone the repository to a temporary location:
    ```bash
    git clone https://github.com/yourusername/multidash.git /tmp/multidash
    ```
3.  Build the Docker image with the tag `multidash`:
    ```bash
    docker build -t multidash /tmp/multidash
    ```
4.  (Optional) Clean up:
    ```bash
    rm -rf /tmp/multidash
    ```

### Step 2: Create the Container
You can now create a container using this local image.

**Option A: Manual Configuration**
1.  Go to the **Docker** tab and click **Add Container**.
2.  **Name:** `MultiDash`
3.  **Repository:** `multidash` (matches the tag you built above)
4.  **Network Type:** `Bridge`
5.  **WebUI:** `http://[IP]:[PORT:80]`
6.  Add a **Port** mapping:
    *   Container Port: `80`
    *   Host Port: `8088` (or your preferred port)
7.  Add a **Path** mapping:
    *   Container Path: `/config`
    *   Host Path: `/mnt/user/appdata/multidash`
8.  Add **Variables**:
    *   Key: `PUID`, Value: `99` (Default unRAID user)
    *   Key: `PGID`, Value: `100` (Default unRAID group)
9.  Click **Apply**.

**Option B: XML Template**
If you have access to place files in your flash drive:
1.  Copy the `multidash-unraid.xml` file from this repo to `/boot/config/plugins/dockerMan/templates-user/my-multidash.xml`.
2.  In unRAID Docker tab, click **Add Container**.
3.  Select **my-multidash** from the "Template" dropdown.
4.  Ensure **Repository** is set to `multidash`.
5.  Click **Apply**.

## FAQ

**Do I need a separate GitHub repository for unRAID?**
No. You can keep the `Dockerfile` and unRAID XML template in this repository. If you decide to publish your application to the unRAID Community Applications (CA) store in the future, you will need to publish your image to Docker Hub (e.g., `youruser/multidash`) and may want a separate repository for your templates, but for personal use, this setup is sufficient.

**Can this Docker setup be used for development?**
Yes. You can mount the source code directly for development:
```yaml
volumes:
  - .:/var/www/html
  - ./config:/config
```
*Note: If you mount the root directory, the `docker-entrypoint.sh` symlinking logic might behave differently (it won't overwrite your local source files), but it is generally safe.*
