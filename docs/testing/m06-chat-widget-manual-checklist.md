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

## M06.3 — chat identity, lifecycle, and presentation (docs/plans/m06-3-chat-identity-lifecycle-presentation-plan-v1.md §16, ADR-0024)

- [ ] The required-name step (disclosure sentence stating the name is
      shared with the support team in Telegram) appears before the first
      send, and blocks an empty submission with an inline error.
- [ ] Reloading the page mid-conversation, before a name was supplied,
      shows the required-name step again (not the composer).
- [ ] Once a conversation has a stored name, reloading shows the composer
      directly, not the name step.
- [ ] The resulting Telegram forum topic's title shows the display name
      and a short reference; the first message in that topic carries the
      one-line `[name · ref]` context header, and no later message repeats
      it.
- [ ] Switching `chat_widget_preset` (Settings tab) changes the widget's
      visible styling after the site's own page-cache purge/expiry — not
      claimed instant on a still-cached page.
- [ ] With `prefers-reduced-motion: reduce` enabled at the OS level, the
      widget shows no motion regardless of the admin's
      `chat_widget_motion_default` setting.
- [ ] Keyboard-only navigation and a screen reader can complete the full
      name-then-send flow without a mouse.
- [ ] 375px/414px mobile viewports show no obstruction of the name step or
      composer.
- [ ] The Bots tab shows chat-widget-created Telegram topics in a separate,
      read-only "Conversation topics" section, with no "Send test message"
      action.
- [ ] Archival behaviour is confirmed via a safe test fixture or dry-run
      evidence (e.g. backdating a test conversation's `updated_at` and
      running the retention cleanup action directly) — not a real 30-day
      wait.

## M06.3.1 — authenticated chat access and UX redesign (docs/plans/m06-3-1-authenticated-chat-access-ux-plan-v1.md, ADR-0025)

Supersedes the M06.3 required-name checklist above (that flow no longer exists).

- [ ] Logged out, opening the widget shows only "Sign in to chat" (and
      "Create account" only when the site allows registration) — no
      composer, history, name field, or conversation control appears.
- [ ] After signing in and reopening the widget, the composer is enabled
      immediately, with no visible "Start chat" control anywhere.
- [ ] Sending the first message succeeds without any prior visible action
      having created a conversation (no network activity to `/conversations`
      until Send is pressed).
- [ ] The resulting Telegram forum topic's title shows the WordPress
      account's display name (or the generic fallback, if the account's
      display name is empty) and a short reference — never the numeric
      user id, username, or email.
- [ ] Opening the widget in a second, separate browser (same account,
      logged in there too) resumes the same conversation and its history.
- [ ] "Close" only hides the widget; reopening it resumes the same
      conversation without re-signing-in.
- [ ] No visible "End conversation" control exists anywhere in the widget.
- [ ] Scrolling up in the message log while a new message arrives does not
      force the view back down; a "New messages" control appears and,
      when clicked, scrolls to the newest message.
- [ ] With the default `chat_widget_preset` (`theme`), the widget's colors
      visually follow the active site theme rather than a fixed purple.
- [ ] Keyboard-only navigation and a screen reader can complete the full
      sign-in-then-send flow without a mouse.
- [ ] 375px/414px mobile viewports show no obstruction of the sign-in
      state or the composer.
- [ ] Deleting the signed-in WordPress account (as an administrator, on a
      disposable test account) leaves its prior conversation's messages
      intact in the database but permanently unreachable through the
      widget/API.
- [ ] Archival behaviour (including a conversation stuck in `new`) is
      confirmed via a safe test fixture or dry-run evidence — not a real
      30-day wait.

## M06.3.1 addendum — configurable anonymous chat (docs/plans/m06-3-1-authenticated-chat-access-ux-addendum-v1.md, ADR-0025)

- [ ] With "Allow anonymous chat" OFF (default), the logged-out behaviour above is unchanged —
      no composer appears.
- [ ] With "Allow anonymous chat" ON, a logged-out visitor's widget shows an enabled composer
      immediately (no sign-in prompt, no name field, no "Start chat").
- [ ] An anonymous conversation's Telegram topic title reads "Visitor · <short reference>" — never
      a real name, IP, or any other identifying detail.
- [ ] A logged-in visitor's widget still uses the authenticated flow even when "Allow anonymous
      chat" is ON (no behavioural difference from it being OFF).
- [ ] Turning "Allow anonymous chat" OFF while an anonymous conversation is mid-session causes that
      conversation's next message/poll to end gracefully (the existing "ended" state), not an error.
