# M06 Chat Widget — Manual Accessibility/Mobile Checklist

Executed once, after WP0–WP7 land and the lean automated gate is green
(docs/plans/m06-chat-widget-plan-v1.md §9). Not part of CI — this
repository has no jsdom/browser test runner, so real keyboard/focus/ARIA
behavior and mobile viewport rendering cannot be automated here.

## Keyboard and focus

- [ ] Tab reaches the toggle button; Enter/Space opens the panel.
- [ ] On open, focus moves to the message input.
- [ ] Tab/Shift+Tab cycles only within the panel (focus trap) while open.
- [ ] Escape closes the panel and returns focus to the toggle button.
- [ ] Closing via the close button also returns focus to the toggle button.

## Screen reader / ARIA

- [ ] The panel is announced as a dialog with the heading "Chat".
- [ ] New operator messages are announced via the log region without
      interrupting the visitor's typing.
- [ ] Status changes (sending, unavailable, transient failure, ended) are
      announced via the status region.

## Visual / motion

- [ ] Contrast of visitor/operator bubbles and controls meets WCAG AA
      against both the widget's own background and typical page
      backgrounds.
- [ ] With `prefers-reduced-motion: reduce` enabled at the OS level, the
      open/close transition is instant (no animation).

## Mobile (375px and 414px viewport widths)

- [ ] The open panel does not overlap the theme's add-to-cart button.
- [ ] The open panel does not overlap the WooCommerce mini-cart/checkout
      button.
- [ ] The close control remains reachable and tappable without scrolling.

## Real-bot smoke acceptance (docs/plans/m06-chat-widget-plan-v1.md §7)

Requires a real dev bot and a forum-enabled Telegram supergroup — not
configured during automated validation.

- [ ] Sending a message from the widget produces the corresponding
      Telegram topic/message.
- [ ] An operator reply in Telegram surfaces via the widget's poll within
      one polling interval.
