import asyncio
import os
from playwright.async_api import async_playwright, expect

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page()

        # Listen for console events and print them
        page.on("console", lambda msg: print(f"Browser console: {msg.text}"))

        try:
            # Load the local HTML file
            html_path = os.path.abspath('jules-scratch/verification/test.html')
            await page.goto(f"file://{html_path}")

            # Wait for the button to appear to ensure JS has run
            add_button_locator = page.locator('.mp-node > .mp-row .mp-add').first
            await expect(add_button_locator).to_be_visible(timeout=10000)

            # 1. Test adding a child node
            await add_button_locator.click()

            # 2. Test entering text into the new node
            child_node_locator = page.locator('.mp-node > .mp-children > .mp-node').first
            await expect(child_node_locator).to_be_visible()

            await child_node_locator.locator('.mp-text').fill('Child Idea')
            await child_node_locator.locator('.mp-notes').fill('Some notes for the child.')

            # 3. Test collapsing and expanding
            await page.click('#mp-collapse-all')
            await expect(child_node_locator).not_to_be_visible()
            await page.click('#mp-expand-all')
            await expect(child_node_locator).to_be_visible()

            # 4. Test Search
            await page.fill('#mp-search', 'child')
            # Root is parent, should be visible
            await expect(page.locator('.mp-node').first).to_be_visible()
            # Child node should be visible because it matches the search
            await expect(child_node_locator).to_be_visible()
            # Check for highlighting
            await expect(child_node_locator.locator('.mp-text')).to_have_css('background-color', 'rgb(255, 255, 0)')

            # Clear search
            await page.fill('#mp-search', '')
            await expect(child_node_locator.locator('.mp-text')).not_to_have_css('background-color', 'rgb(255, 255, 0)')

            # Take a screenshot to verify the UI
            await page.screenshot(path="jules-scratch/verification/verification.png")
            print("Successfully created verification.png")


        except Exception as e:
            print(f"An error occurred during Playwright script execution: {e}")
            # On error, save a screenshot and the page content for debugging
            await page.screenshot(path="jules-scratch/verification/error.png")
            content = await page.content()
            with open("jules-scratch/verification/error.html", "w", encoding="utf-8") as f:
                f.write(content)
            print("Created error.png and error.html for debugging.")
            # Re-raise the exception to fail the step
            raise

        finally:
            await browser.close()

if __name__ == "__main__":
    asyncio.run(main())