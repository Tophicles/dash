
import os
import time
from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    # --- Mocks ---

    # 1. get_config.php
    page.route("**/get_config.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"servers": ['
             '{"id": "1", "name": "Main Plex", "type": "plex", "url": "http://plex:32400", "enabled": true, "order": 1, "os_type": "linux", "ssh_initialized": true},'
             '{"id": "2", "name": "Emby Server", "type": "emby", "url": "http://emby:8096", "enabled": true, "order": 2, "os_type": "linux", "ssh_initialized": true}'
             '], "refreshSeconds": 5}'
    ))

    # 2. proxy.php (General Dispatch)
    def handle_proxy(route, request):
        url = request.url

        # Server Status / Sessions
        if "server=Main%20Plex" in url and "action=info" not in url:
            # Plex with Active Session
            route.fulfill(
                status=200,
                content_type="application/json",
                body='{"MediaContainer": {"Metadata": [{'
                     '"User": {"title": "Alice"}, "title": "Inception", "grandparentTitle": "", "viewOffset": 3500000, "duration": 8000000, '
                     '"Player": {"state": "playing", "title": "Apple TV", "product": "Plex for tvOS"}, '
                     '"ratingKey": "100", "Media": [{"width": 3840, "height": 2160, "selected": "directplay"}]'
                     '}]}}'
            )
            return

        if "server=Emby%20Server" in url and "action=info" not in url:
            # Emby Idle
            route.fulfill(status=200, content_type="application/json", body='[]')
            return

        # Server Info (Update Check)
        if "action=info" in url:
            if "Emby" in url or "id=2" in url:
                # Emby has update
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body='{"version": "4.7.0.0", "updateAvailable": true}'
                )
            else:
                # Plex no update
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body='{"version": "1.32.0.6950", "updateAvailable": false}'
                )
            return

        # SSH Status
        if "action=ssh_status" in url:
            route.fulfill(status=200, content_type="application/json", body='{"success": true, "status": "active"}')
            return

        # Default empty
        route.fulfill(status=200, content_type="application/json", body='[]')

    page.route("**/proxy.php*", handle_proxy)

    # 3. get_active_users.php
    page.route("**/get_active_users.php*", lambda route: route.fulfill(
        status=200, content_type="application/json", body='{"users": ["Admin", "Viewer"]}'
    ))

    # 4. manage_users.php
    page.route("**/manage_users.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"success": true, "users": ['
             '{"username": "Admin", "role": "admin", "created": "2023-01-01"},'
             '{"username": "Viewer", "role": "viewer", "created": "2023-02-01"}'
             ']}'
    ))

    # 5. ssh_manager.php
    page.route("**/ssh_manager.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"success": true, "key": "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQ... (Public Key Content) ..."}'
    ))

    # 6. get_item_details.php (Rich Metadata for Tech Badges)
    page.route("**/get_item_details.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"success": true, "item": {'
             '"title": "Inception", "year": 2010, "rating": "PG-13", "runtime": "2h 28m", '
             '"resolution": "3840x2160", "container": "mkv", "audioCodec": "TrueHD", "audioChannels": "7.1", '
             '"poster": "data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjMmMyYzJjIi8+CiAgPGNpcmNsZSBjeD0iMTAwIiBjeT0iMTAwIiByPSI1MCIgZmlsbD0iI2U1YTAwZCIvPgogIDx0ZXh0IHg9IjUwJSIgeT0iODAlIiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjZmZmIiBmb250LXNpemU9IjI0IiBmb250LWZhbWlseT0ic2Fucy1zZXJpZiI+SW5jZXB0aW9uPC90ZXh0Pgo8L3N2Zz4=", '
             '"overview": "A thief who steals corporate secrets through the use of dream-sharing technology...", '
             '"genres": "Action, Sci-Fi", "director": "Christopher Nolan", '
             '"path": "/data/media/movies/Inception (2010)/Inception.2010.2160p.mkv"'
             '}}'
    ))

    # --- Execution ---

    # 1. Login
    print("Navigating to login...")
    page.goto("http://localhost:8080/login.php")
    page.wait_for_selector("input[name='username']")
    page.screenshot(path="screenshots/login.png")
    print("Captured login.png")

    # Login Flow
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "password")
    page.click("button[type='submit']")
    page.wait_for_url("**/index.php")

    # 2. Dashboard
    print("Loading dashboard...")
    page.wait_for_selector(".server-card")
    time.sleep(2) # Wait for async fetches (versions, users)
    page.screenshot(path="screenshots/dashboard.png")
    print("Captured dashboard.png")

    # 3. Search Preview
    page.fill("#server-search", "Alice")
    time.sleep(1) # Wait for debounce
    page.screenshot(path="screenshots/search_preview.png")
    print("Captured search_preview.png")

    # Clear search
    page.fill("#server-search", "")
    time.sleep(0.5)

    # 4. Add Server Modal
    page.click("#menu-toggle-btn") # Open Menu
    page.wait_for_selector("#toggle-form", state="visible")
    page.click("#toggle-form")
    page.wait_for_selector("#server-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/add_server.png")
    print("Captured add_server.png")
    page.click("#server-modal .modal-close")
    time.sleep(0.5)

    # 5. User Management
    page.click("#menu-toggle-btn") # Open Menu
    page.wait_for_selector("#users-btn", state="visible")
    page.click("#users-btn")
    page.wait_for_selector("#users-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/users.png")
    print("Captured users.png")
    page.click("#users-modal .modal-close")
    time.sleep(0.5)

    # 6. SSH Keys Management
    # Navigate to it via top bar button
    page.click("#menu-toggle-btn") # Open Menu again
    time.sleep(0.5)
    page.click("#ssh-keys-nav-btn")
    page.wait_for_selector("#ssh-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/ssh_keys.png")
    print("Captured ssh_keys.png")
    # Close modal by clicking outside or mock close
    page.evaluate("document.getElementById('ssh-modal').classList.remove('visible')")
    time.sleep(0.5)

    # 7. Server View (with Update Available)
    # Click on Emby server card (ID 2)
    page.click(".server-card[data-server-id='2']")
    page.wait_for_selector("#sessions-view.visible")
    time.sleep(1) # Wait for controls to render
    page.screenshot(path="screenshots/server_view.png")
    print("Captured server_view.png")

    # 8. Update Modal (Initial)
    page.click("button[title*='Update Available']")
    page.wait_for_selector("#update-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/update_modal.png")
    print("Captured update_modal.png")

    # 9. Update Process Mock (Log Output)
    # Inject text into log output
    log_text = (
        "Initializing update sequence...\\n"
        "Architecture: amd64\\n"
        "Branch: Stable\\n"
        "Downloading update from https://github.com/MediaBrowser/Emby.Releases/...\\n"
        "Download complete (85MB).\\n"
        "Stopping EmbyServer service...\\n"
        "Installing package (dpkg -i)...\\n"
        "(Reading database ... 45032 files and directories currently installed.)\\n"
        "Preparing to unpack .../multidash_update.deb ...\\n"
        "Unpacking emby-server (4.8.0.56) over (4.7.0.0) ...\\n"
        "Setting up emby-server (4.8.0.56) ...\\n"
        "Processing triggers for libc-bin (2.35-0ubuntu3.1) ...\\n"
        "Starting EmbyServer service...\\n"
        "UPDATE_COMPLETE"
    )
    page.evaluate(f"document.getElementById('update-log-output').textContent = `{log_text}`")
    page.evaluate("document.getElementById('start-update-btn').textContent = 'Close'")
    page.evaluate("document.getElementById('start-update-btn').disabled = false")
    time.sleep(0.5)
    page.screenshot(path="screenshots/update_process.png")
    print("Captured update_process.png")

    page.click("#update-modal .btn:not(.primary)") # Click Close
    time.sleep(0.5)

    # 10. Tech Badges (Item Details)
    # Go back to dashboard to click the active session
    page.click("#header-reload-btn")
    page.wait_for_selector(".server-card")

    # Active session is on "Main Plex" (ID 1).
    page.click(".server-card[data-server-id='1']")
    page.wait_for_selector("#sessions-view.visible")
    time.sleep(0.5)

    # Click the session card
    page.click(".session")
    page.wait_for_selector("#item-modal.visible")
    time.sleep(1) # Wait for mock data load
    page.screenshot(path="screenshots/tech_badges.png")
    print("Captured tech_badges.png")

    page.click("#item-modal .modal-close")
    time.sleep(0.5)

    # 11. Backup Modal
    page.click("#menu-toggle-btn") # Open Menu
    time.sleep(0.5)
    page.click("#backup-nav-btn")
    page.wait_for_selector("#backup-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/backup_modal.png")
    print("Captured backup_modal.png")
    page.evaluate("document.getElementById('backup-modal').classList.remove('visible')")
    time.sleep(0.5)

    # 12. Logs
    # Access logs via menu (visual only, we navigate directly for stability)
    page.click("#menu-toggle-btn")
    time.sleep(0.5)

    print("Navigating to logs...")
    page.goto("http://localhost:8080/view_logs.php")
    page.wait_for_selector("#log-container", timeout=10000)
    time.sleep(1)
    page.screenshot(path="screenshots/logs.png")
    print("Captured logs.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
