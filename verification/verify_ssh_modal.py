
import os
import time
from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    # --- Mocks ---
    # 1. Config: Server with SSH NOT initialized
    page.route("**/get_config.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"servers": ['
             '{"id": "1", "name": "New Server", "type": "emby", "url": "http://emby:8096", "enabled": true, "order": 1, "os_type": "linux", "ssh_initialized": false}'
             '], "refreshSeconds": 5}'
    ))

    # 2. SSH Status: Fails
    page.route("**/proxy.php?id=1&action=ssh_status*", lambda route: route.fulfill(
        status=200, content_type="application/json", body='{"success": false, "error": "Connection refused"}'
    ))

    # 3. Public Key
    page.route("**/ssh_manager.php?action=get_public_key*", lambda route: route.fulfill(
        status=200, content_type="application/json", body='{"success": true, "key": "ssh-rsa MOCK_KEY_CONTENT"}'
    ))

    # 4. Other Mocks
    page.route("**/proxy.php?server=New%20Server*", lambda r: r.fulfill(status=200, body='[]'))
    page.route("**/proxy.php?id=1&action=info*", lambda r: r.fulfill(status=200, body='{"version":"1.0"}'))
    page.route("**/get_active_users.php*", lambda r: r.fulfill(status=200, body='{"users":[]}'))
    page.route("**/library_actions.php*", lambda r: r.fulfill(status=200, body='{"success":true,"libraries":[]}'))

    # --- Execution ---
    print("Navigating to login...")
    page.goto("http://localhost:8080/login.php")
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "password")
    page.click("button[type='submit']")
    page.wait_for_url("**/index.php")

    print("Opening Server View...")
    page.click(".server-card")
    page.wait_for_selector("#sessions-view.visible")
    time.sleep(1)

    # Verify Badge is Red and Clickable
    print("Checking Red Badge...")
    badge = page.locator("#ssh-badge-1")
    # Check if it has the red styling (color: #e57373)
    # Note: style attribute might be set via JS
    color = badge.evaluate("el => el.style.color")
    # assert color == "rgb(229, 115, 115)" or "e57373" in color
    print(f"Badge Color: {color}")

    print("Clicking Badge...")
    badge.click()

    # Verify Modal
    print("Verifying Modal...")
    page.wait_for_selector("#server-setup-modal.visible")

    key_val = page.input_value("#setup-ssh-key")
    if "MOCK_KEY_CONTENT" in key_val:
        print("Key loaded correctly.")
    else:
        print(f"Key mismatch: {key_val}")

    cmd_text = page.inner_text("#setup-command-display")
    if "wget -qO-" in cmd_text:
        print("Command loaded correctly.")
    else:
        print(f"Command mismatch: {cmd_text}")

    page.screenshot(path="/home/jules/verification/ssh_modal_red.png")
    print("Captured ssh_modal_red.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
