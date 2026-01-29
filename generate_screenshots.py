
import os
import time
from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1200, "height": 800})

    # --- Mocks ---
    # Mock get_config.php with mixed servers
    page.route("**/get_config.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"servers": ['
             '{"id": "1", "name": "Main Plex", "type": "plex", "url": "http://plex:32400", "enabled": true, "order": 1},'
             '{"id": "2", "name": "Emby Server", "type": "emby", "url": "http://emby:8096", "enabled": true, "order": 2}'
             '], "refreshSeconds": 5}'
    ))

    # Mock proxy.php for Plex (Active Session)
    page.route("**/proxy.php?server=Main%20Plex", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"MediaContainer": {"Metadata": [{"User": {"title": "Alice"}, "title": "Inception", "grandparentTitle": "", "viewOffset": 1000, "duration": 5000, "Player": {"state": "playing"}, "ratingKey": "100"}]}}'
    ))

    # Mock proxy.php for Emby (Idle)
    page.route("**/proxy.php?server=Emby%20Server", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='[]'
    ))

    # Mock get_active_users.php
    page.route("**/get_active_users.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"users": ["Admin", "Viewer"]}'
    ))

    # Mock manage_users.php for User List
    page.route("**/manage_users.php*", lambda route: route.fulfill(
        status=200,
        content_type="application/json",
        body='{"success": true, "users": ['
             '{"username": "Admin", "role": "admin", "created": "2023-01-01"},'
             '{"username": "Viewer", "role": "viewer", "created": "2023-02-01"}'
             ']}'
    ))

    # --- 1. Login Screenshot ---
    # Navigate to login (force logout essentially by hitting login.php directly or ensuring no session)
    # Since we are mocking, we can just hit login.php
    page.goto("http://localhost:8080/login.php")
    page.screenshot(path="screenshots/login.png")
    print(" captured login.png")

    # --- Login Flow ---
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "password")
    page.click("button[type='submit']")
    page.wait_for_url("**/index.php")

    # --- 2. Dashboard Screenshot ---
    page.wait_for_selector(".server-card")
    # Wait for loading to settle
    time.sleep(1)
    page.screenshot(path="screenshots/dashboard.png")
    print(" captured dashboard.png")

    # --- 3. Add Server Modal Screenshot ---
    page.click("#menu-header") # Open Menu
    page.wait_for_selector("#toggle-form", state="visible")
    page.click("#toggle-form")
    page.wait_for_selector("#server-modal.visible")
    time.sleep(0.5)
    page.screenshot(path="screenshots/add_server.png")
    print(" captured add_server.png")

    # Close modal
    page.click("#server-modal .modal-close")
    time.sleep(0.5)

    # --- 4. User Management Screenshot ---
    page.click("#users-btn")
    page.wait_for_selector("#users-modal.visible")
    page.wait_for_selector(".user-item") # Wait for list to load
    time.sleep(0.5)
    page.screenshot(path="screenshots/users.png")
    print(" captured users.png")

    # Close modal
    page.click("#users-modal .modal-close")
    time.sleep(0.5)

    # --- 5. Search Preview Screenshot ---
    # Close menu first to clean up UI
    page.click("#menu-header")
    time.sleep(0.5)

    page.fill("#server-search", "Alice")
    time.sleep(0.5) # Wait for debounce/filter
    page.screenshot(path="screenshots/search_preview.png")
    print(" captured search_preview.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
