from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 800})
    page = context.new_page()

    # 1. Login
    page.goto("http://localhost:8000/login.php")
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "admin123")
    page.click("button[type='submit']")

    # Wait for dashboard
    expect(page.locator("#server-grid")).to_be_visible()

    # Ensure menu is open if needed
    if page.locator("#menu-content").get_attribute("class") and "hidden" in page.locator("#menu-content").get_attribute("class"):
         page.click("#menu-header")
         expect(page.locator("#menu-content")).to_be_visible()

    # 2. Open Server Admin Modal
    page.click("#server-admin-btn")
    expect(page.locator("#server-admin-modal")).to_be_visible()

    # Find Linux Server Row (created by setup_data_linux.php previously)
    row = page.locator(".admin-server-item", has_text="LinuxServer")
    expect(row).to_be_visible()

    # Check for Spinner or Error (indicates status check attempted)
    # The timeout is short in verify script, but UI might persist error
    # We just want to ensure the logic triggered the "Check Status" path which replaces the placeholder

    # Wait a bit for async fetch
    page.wait_for_timeout(2000)

    # Since network call fails, we expect the error message
    error_span = row.locator("span[title*='Error']") # "Could not determine host" or "Connection refused"

    # Or, if the mock setup data url 'localhost:8096' is reachable?
    # Actually, proxy.php tries to connect to localhost:8096/System/Info first?
    # No, ssh_status action calls `systemctl is-active` via SSH.
    # SSH to localhost:22 as mediasvc will definitely fail in this environment (no key, wrong user).
    # So we expect failure.

    # The UI code:
    # else { container.innerHTML = `<span style="font-size:0.8rem; color:red;" title="${esc(data.error)}">Error</span>`; }

    if error_span.is_visible():
        print("PASS: Status check failed as expected (Error displayed).")
    else:
        # Maybe it's still spinning?
        if row.locator(".fa-spinner").is_visible():
             print("PASS: Spinner still visible (Timeout handling might differ).")
        else:
             print("FAIL: Neither Error nor Spinner found.")

    # Screenshot
    page.screenshot(path="verification/verify_status_ui.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
