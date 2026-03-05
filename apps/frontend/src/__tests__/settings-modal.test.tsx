import { render, screen } from "@testing-library/react";
import { SettingsModal } from "@/components/SettingsModal";

jest.mock("@/hooks/useFeatureFlags", () => ({
  useFeatureFlags: () => ({
    dates: false,
    delegation: false,
    setFlag: jest.fn(),
    loaded: true,
  }),
}));

jest.mock("@/components/PushNotificationToggle", () => ({
  PushNotificationToggle: () => <div data-testid="push-toggle" />,
}));

describe("SettingsModal — bottom sheet layout", () => {
  test("renders nothing when closed", () => {
    const { container } = render(
      <SettingsModal isOpen={false} onClose={jest.fn()} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  // jsdom can't measure layout, so we assert the class contract that
  // produces the scroll behaviour. If someone strips any of these the
  // sheet reverts to growing past the viewport.
  test("sheet is height-capped with a pinned header and scrollable body", () => {
    render(<SettingsModal isOpen onClose={jest.fn()} />);

    const heading = screen.getByRole("heading", { name: "Settings" });
    const header = heading.parentElement!;
    const sheet = header.parentElement!;
    const body = header.nextElementSibling!;

    // Sheet: capped + flex-column so children stack vertically and the
    // body can claim leftover height.
    expect(sheet.className).toMatch(/\bmax-h-\[85vh\]/);
    expect(sheet.className).toMatch(/\bflex\b/);
    expect(sheet.className).toMatch(/\bflex-col\b/);

    // Header pinned — never scrolls away, close button always reachable.
    expect(header.className).toMatch(/\bshrink-0\b/);

    // Body: fills remaining space and scrolls internally. min-h-0 is the
    // load-bearing bit — without it flexbox won't shrink the item below
    // its content height and the cap is ignored.
    expect(body.className).toMatch(/\bflex-1\b/);
    expect(body.className).toMatch(/\bmin-h-0\b/);
    expect(body.className).toMatch(/\boverflow-y-auto\b/);
  });

  test("header carries the close button so it stays reachable while scrolled", () => {
    render(<SettingsModal isOpen onClose={jest.fn()} />);

    const header = screen.getByRole("heading", {
      name: "Settings",
    }).parentElement!;
    const close = screen.getByRole("button", { name: /close/i });

    expect(header.contains(close)).toBe(true);
    expect(header.className).toMatch(/\bshrink-0\b/);
  });
});
