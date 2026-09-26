from playwright.sync_api import sync_playwright

BASE = "http://localhost:8004"

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1440, "height": 900})
    page.set_default_timeout(180000)
    page.set_default_navigation_timeout(180000)

    page.goto(f"{BASE}/login")
    page.fill("#email", "admin@billcycle.demo")
    page.fill("#password", "password")
    page.click('button[type="submit"]')
    page.wait_for_url(f"{BASE}/", wait_until="load")
    page.wait_for_selector("h1:has-text('Dashboard')")
    page.screenshot(path="/out/dashboard.png")

    browser.close()
    print("saved")
