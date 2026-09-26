"""
Capture README screenshots with Playwright, against the real running app
(billcycle-nginx-1 on the host network at localhost:8004) with real seeded
data -- not mockups.

Run from the host (not inside the compose network), since this script talks
to localhost:8004 directly:

    docker run --rm --network host \
        -v "$(pwd)/docs/images:/out" \
        mcr.microsoft.com/playwright/python:v1.49.0-noble \
        python /out/capture_screenshots.py
"""

import pathlib

from playwright.sync_api import sync_playwright

BASE = "http://localhost:8004"
OUT = pathlib.Path("/out")
VIEWPORT = {"width": 1440, "height": 900}


def login(page):
    page.goto(f"{BASE}/login")
    page.fill("#email", "admin@billcycle.demo")
    page.fill("#password", "password")
    page.click('button[type="submit"]')
    page.wait_for_load_state("networkidle")


def find_customer_id(page, status_label):
    """Find a customer row with the given status badge text and return its id
    from the row's link href."""
    page.goto(f"{BASE}/customers")
    page.wait_for_load_state("networkidle")
    rows = page.locator("tbody tr")
    for i in range(rows.count()):
        row = rows.nth(i)
        if status_label in row.inner_text():
            href = row.locator("a").first.get_attribute("href")
            return href.rstrip("/").split("/")[-1]
    return None


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport=VIEWPORT)
        page.set_default_timeout(90000)

        login(page)

        # 1. Dashboard
        page.goto(f"{BASE}/")
        page.wait_for_load_state("networkidle")
        page.screenshot(path=str(OUT / "dashboard.png"))

        # 2. Customer list
        page.goto(f"{BASE}/customers")
        page.wait_for_load_state("networkidle")
        page.screenshot(path=str(OUT / "customers.png"))

        # 3. Customer detail with dunning timeline (a past-due customer)
        past_due_id = find_customer_id(page, "Past Due")
        if past_due_id:
            page.goto(f"{BASE}/customers/{past_due_id}")
            page.wait_for_load_state("networkidle")
            page.screenshot(path=str(OUT / "dunning-timeline.png"))

        # 4. Plan change proration preview (an active customer)
        active_id = find_customer_id(page, "Active")
        if active_id:
            page.goto(f"{BASE}/customers/{active_id}/change-plan")
            page.wait_for_load_state("networkidle")
            select = page.locator("#plan_id")
            options = select.locator("option")
            # pick the last non-disabled option (a different, usually higher, plan)
            for i in range(options.count() - 1, -1, -1):
                opt = options.nth(i)
                if not opt.is_disabled():
                    select.select_option(value=opt.get_attribute("value"))
                    break
            page.wait_for_load_state("networkidle")
            page.screenshot(path=str(OUT / "plan-change-preview.png"))

            # 5. Invoice detail -- go back and open an invoice
            page.goto(f"{BASE}/customers/{active_id}")
            page.wait_for_load_state("networkidle")
            invoice_link = page.locator('a[href*="/invoices/"]').first
            if invoice_link.count() > 0:
                invoice_link.click()
                page.wait_for_load_state("networkidle")
                page.screenshot(path=str(OUT / "invoice-detail.png"))

        browser.close()
        print("Screenshots saved to", OUT)


if __name__ == "__main__":
    main()
