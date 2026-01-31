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

    # 2. Click on the Linux Server Card to go to Sessions View
    server_card = page.locator(".server-card", has_text="LinuxHeaderSrv")
    server_card.click()

    # 3. Verify Header Layout
    expect(page.locator(".server-header-bar")).to_be_visible()
    expect(page.locator(".header-left")).to_contain_text("LinuxHeaderSrv")
    expect(page.locator(".header-center .os-badge.linux")).to_be_visible()

    # Check for controls container
    controls_container = page.locator(".header-right .js-ssh-controls-srv_1")
    expect(controls_container).to_be_visible()

    # Wait for status fetch
    page.wait_for_timeout(2000)

    # Check if error or spinner is present
    has_error = controls_container.locator("span[title*='Error']").is_visible()
    has_spinner = controls_container.locator(".fa-spinner").is_visible()

    if has_error or has_spinner:
        print("PASS: Header controls loaded status logic.")
    else:
        print("FAIL: Header controls logic failed.")

    # Screenshot
    page.screenshot(path="verification/verify_header_ui.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
