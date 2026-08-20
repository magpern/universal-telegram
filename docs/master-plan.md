# Master Plan — Telegram Operations, Automation, Chat and AI Plugin for WordPress

> Governance: see docs/governance.md for roles, lifecycle, and closure statuses. Milestone charters: see docs/milestones/README.md.
>
> Naming: official name "Telegram Operations Hub for WordPress"; slug/repository/folder/text domain "universal-telegram" — finalized in docs/adr/0002-plugin-identity-and-naming.md.
>
> WooCommerce: optional integration, not a hard dependency — see docs/adr/0003-optional-woocommerce-integration.md. Core WordPress and Telegram functionality (M00–M02, M05–M07) works without WooCommerce present; WooCommerce-specific functionality (M03, and the order/stock/sales commands in M08) activates only when WooCommerce is active.
>
> Execution sequence and v1.0 boundary: milestones execute M00, M01, M02, M03, M04, M05, M06, M07, then M12 as the mandatory v1.0 hardening and release gate. M08–M11 are post-v1.0 — they do not execute before the v1.0 release, and their own release/hardening gate is a future, unnumbered roadmap decision — see docs/adr/0004-v1-release-boundary-and-hardening-sequence.md. This clarifies, and takes precedence over, the sequential M0–M12 numbering used elsewhere in this document, which denotes milestone identity only, not execution order.
>
> Self-contained deployment: Telegram routing, conversation state, website chat, and AI orchestration run within WordPress. External Telegram and AI-provider APIs may be called asynchronously, but no companion bot server or vendor-hosted SaaS backend is required. This confirms the existing plugin architecture; it does not introduce a companion service or a new milestone.

## 1. Product vision

Build a new WordPress plugin that connects WordPress and WooCommerce with Telegram through four integrated capabilities:

1. **Event monitoring** — capture operational, commerce and visitor events.
2. **Notification automation** — send configurable Telegram alerts based on rules.
3. **Customer chat** — connect a website chat widget with Telegram-based operators.
4. **AI assistance** — draft or provide controlled answers using approved site information.

The plugin should replace hard-coded event checkboxes with a reusable event-and-rule architecture.

Working name:

**Telegram Operations Hub for WordPress**

The official product name and technical identifiers are finalized in ADR-0002.

---

## 2. Primary use cases

### Notifications

* Notify Telegram when selected WordPress or WooCommerce events occur.
* Select exactly which pages, products, visitors or circumstances qualify.
* Route different events to different Telegram chats, channels or topics.
* Aggregate routine activity rather than sending excessive individual messages.
* Send urgent operational errors immediately.

### Website chat

* Display a configurable chat widget on selected pages.
* Forward customer messages to Telegram.
* Give each customer conversation its own Telegram forum topic.
* Allow staff to reply from Telegram.
* Deliver replies back to the customer’s website chat.
* Preserve conversations if the visitor navigates between pages.
* Support online, busy and offline modes.

### Telegram administration

* Query site and shop status from Telegram.
* Retrieve orders, stock, errors and activity summaries.
* Perform carefully controlled actions with confirmation and audit logging.

### AI assistance

* Draft responses for operators.
* Answer approved customer questions using site content.
* Summarize conversations, events and errors.
* Escalate uncertain or sensitive questions.
* Optionally invoke explicitly approved, restricted tools.

---

## 3. Product principles

### Modular design

Events, rules, Telegram transport, conversations, widget presentation and AI must remain separate modules.

### Asynchronous operation

Telegram and AI requests must run through a queue. External service failures must never delay or break WordPress page loads, cart operations or checkout.

### Privacy by default

Collect the minimum required information. Redact sensitive fields and avoid sending unnecessary personal data to Telegram or AI providers.

### Human control

AI begins as an operator drafting tool. Automatic customer replies are introduced only after retrieval, safety and escalation behaviour have been validated.

### Extensibility

Other plugins must be able to register events, conditions, message variables, bot commands and AI tools without modifying the core plugin.

### Compatibility

Support:

* Current maintained WordPress versions
* WooCommerce HPOS
* Classic WooCommerce cart and checkout
* WooCommerce Cart and Checkout blocks
* Full-page caching
* WordPress multisite where practical
* Common multilingual plugins

---

# 4. Functional architecture

## 4.1 Event system

All captured activity is normalized into a common event structure:

```text
Event
├── Event type
├── Timestamp
├── Source
├── Severity
├── Subject
├── Visitor/session reference
├── Page context
├── Commerce context
├── Structured metadata
└── Privacy classification
```

Example event types:

```text
visitor.page_viewed
visitor.search_performed
commerce.product_viewed
commerce.cart_item_added
commerce.checkout_started
commerce.checkout_failed
commerce.order_created
commerce.payment_failed
wordpress.login_failed
wordpress.fatal_error
chat.conversation_started
chat.message_received
system.queue_failed
custom.*
```

Events must carry structured data rather than preformatted Telegram messages.

## 4.2 Rule engine

A rule consists of:

```text
WHEN an event occurs
IF configured conditions match
THEN execute one or more actions
```

Example:

```text
WHEN commerce.checkout_failed
IF payment method = Stripe
AND cart total > €100
AND failure count during session >= 2
THEN send an urgent Telegram notification
AND offer checkout chat automatically
```

Supported condition groups:

* Event type and severity
* Page, URL or post type
* Product, category or cart contents
* Order value, status or payment method
* User role or authentication state
* Country, currency or language
* Device type
* Referral and campaign
* Business hours
* Frequency or threshold
* Custom metadata
* Nested AND/OR groups

Supported actions:

* Send Telegram message
* Send Telegram digest
* Start or highlight a chat
* Store event
* Call registered extension action
* Invoke an approved AI workflow
* Send a generic webhook in a later release

## 4.3 Telegram transport

Support:

* Multiple bots
* Multiple destinations
* Private chats
* Groups
* Channels
* Forum topics
* Destination-specific templates
* Connection testing
* Retry and backoff
* Rate limiting
* Duplicate suppression
* Delivery logs
* Webhook verification
* Telegram API diagnostics

Tokens must be protected and never exposed to frontend code.

## 4.4 Event storage

Not every event needs permanent storage.

Administrators can configure:

* Which event classes are stored
* Retention by event type
* Aggregated versus individual storage
* Anonymous session identifiers
* Automatic deletion
* Data export and erasure
* Debug logging duration

High-volume events such as page views should be aggregated or sampled by default.

---

# 5. Event coverage

## 5.1 WordPress events

* Login succeeded or failed
* Administrator login
* User registration
* User role changed
* Password reset
* Post created, published or updated
* Comment submitted
* Plugin activated or deactivated
* Plugin, theme or core update available
* Update completed or failed
* Scheduled task failed
* REST request failed
* Email sending failed
* PHP fatal error
* Configurable WooCommerce log severity

## 5.2 WooCommerce events

* Product viewed
* Product added to or removed from cart
* Cart value crosses a threshold
* Coupon applied or rejected
* Checkout viewed
* Checkout started
* Place-order action initiated
* Checkout validation failed
* Shipping unavailable
* Payment failed
* Order created
* Order paid
* Order status changed
* Refund created
* Product low or out of stock

Server-authoritative events should be preferred whenever available.

Browser-side tracking is required for cached page views, interface interactions, JavaScript errors and some block-based checkout states.

## 5.3 Visitor events

* Session started
* Page viewed
* Navigation between selected pages
* Search performed
* Search returned no results
* External referral
* Configured button clicked
* JavaScript error
* Funnel step reached
* Configured inactivity or abandonment signal

Visitor tracking must support consent-aware operation and bot filtering.

## 5.4 Extension API

Custom plugins can emit normalized events:

```php
do_action(
    'telegram_operations_event',
    'inventory.delivery_delayed',
    [
        'purchase_order' => 'PO-1042',
        'delay_days'     => 5,
    ]
);
```

The public API should also permit registration of:

* Event schemas
* Rule conditions
* Message variables
* Bot commands
* AI tools
* Privacy classifications

---

# 6. Customer chat

## 6.1 Conversation model

Each conversation contains:

* Public conversation identifier
* Secure visitor access token
* Website session reference
* Telegram destination and topic
* Chat profile
* Status
* Assigned operator
* Participant information
* Messages and attachments
* Created, updated and resolved times
* Consent and retention metadata
* AI participation state

Conversation states:

```text
New → Open → Waiting for visitor/operator → Resolved → Archived
```

## 6.2 Telegram conversation routing

Preferred architecture:

* One Telegram support group
* Forum topics enabled
* One topic created per website conversation
* Topic title contains a non-sensitive reference and optional topic
* Visitor context appears in the first Telegram message
* Operator replies are routed back to the correct website conversation

Alternative routing should support separate Telegram groups or topics for sales, checkout and order support.

## 6.3 Visitor continuity

The chat must survive normal page navigation.

Support:

* Secure browser conversation token
* Local storage or cookie, depending privacy configuration
* Server-side conversation state
* Reconnection after page navigation
* Unread message count
* Optional email continuation
* Configurable expiration

No Telegram credentials or internal identifiers may be exposed to visitors.

## 6.4 Attachments

Later chat releases may support:

* Images
* Documents
* Screenshots

Controls must include:

* Allowed file types
* Maximum size
* Malware scanning integration point
* Storage retention
* Download authorization
* Removal from Telegram messages where required

---

# 7. Chat widget and administrator settings

## 7.1 Presentation modes

* Floating bubble
* Floating labelled button
* Inline shortcode
* Gutenberg block
* Elementor widget
* Custom JavaScript or CSS-selector trigger

## 7.2 Placement

Desktop and mobile settings:

* Bottom right
* Bottom left
* Horizontal offset
* Vertical offset
* Width and maximum height
* Mobile full-screen or near-full-screen mode
* Configurable stacking order
* Safe placement around cookie notices and mobile navigation

Optional visitor dragging may be added later. Continuous movement around the page will not be supported.

## 7.3 Open and close behaviour

Configurable options:

* Initially open or closed
* Open after a delay
* Open after scroll threshold
* Open after a configured event
* Desktop exit intent
* Open once per session
* Allow minimize
* Allow complete close
* Keep reopen launcher visible
* Remember closed or minimized state
* Reset state after configured duration
* Show unread count
* Optional notification sound
* Optional automatic reopening after a reply

Default:

* Initially closed
* Closable
* Always reopenable
* Closed state remembered for the session
* No sound or automatic opening

## 7.4 Page targeting

Chat profiles use the shared rule engine.

Include or exclude by:

* Entire website
* Homepage
* Shop
* Product or category
* Cart and checkout
* Order confirmation
* My Account
* Blog or research content
* Individual pages
* Post type
* URL pattern
* Authentication state
* User role
* Country, language or currency
* Device type
* Campaign or referrer
* Business hours

The administrator interface should explain which profile wins when several rules match.

## 7.5 Chat profiles

Support multiple profiles, for example:

| Profile       | Placement                | Telegram destination | Purpose             |
| ------------- | ------------------------ | -------------------- | ------------------- |
| Sales         | Product pages            | Sales topics         | Product questions   |
| Checkout help | Cart and checkout        | Operations topics    | Purchase assistance |
| Order support | My Account               | Support topics       | Existing orders     |
| General       | Remaining eligible pages | General support      | Other questions     |

Each profile controls:

* Availability rules
* Destination
* Appearance
* Welcome message
* Pre-chat form
* AI mode
* Operator schedule
* Privacy notice
* Priority

Only one profile renders at a time.

## 7.6 Visual customization

Settings:

* Primary and contrast colours
* Light, dark or automatic theme
* Bubble size and icon
* Header and avatar
* Typography
* Border radius
* Shadow
* Panel dimensions
* Animation
* Welcome and status text
* Mobile layout

## 7.7 Custom CSS

Provide:

1. Standard visual controls
2. Stable CSS custom properties
3. Advanced scoped CSS editor

Documented selectors must remain backward compatible:

```css
.mp-chat {}
.mp-chat__launcher {}
.mp-chat__panel {}
.mp-chat__header {}
.mp-chat__messages {}
.mp-chat__composer {}
.mp-chat__unread-count {}
```

Custom CSS requirements:

* Scoped to the widget
* Capability restricted
* Syntax checked
* Live preview
* Desktop and mobile preview
* Reset and revision support
* Export and import
* Delivered through a cached stylesheet

## 7.8 Text and localization

All visible strings must be configurable and translatable:

* Launcher label
* Welcome text
* Input placeholder
* Waiting status
* Offline message
* AI disclosure
* Privacy notice
* Consent label
* Error messages
* Reconnection text

Support WordPress locale plus WPML and Polylang integration points.

## 7.9 Business hours

Per-profile availability:

* Time zone
* Weekly schedule
* Holiday exceptions
* Manual override
* Online, busy and offline modes
* Maximum concurrent conversations
* Expected-response-time text
* Offline message collection

Authorized operators may change state from Telegram.

## 7.10 Accessibility

The widget must include:

* Complete keyboard navigation
* Visible focus indicators
* Semantic controls and screen-reader labels
* Focus trapping while open
* Escape-to-minimize behaviour
* Sufficient contrast
* Reduced-motion support
* Minimum 44×44-pixel touch targets
* Mobile keyboard accommodation
* No mandatory animation or sound

---

# 8. Telegram administration bot

## 8.1 Read-only commands

Initial commands:

```text
/status
/orders today
/order 12345
/stock <product>
/errors
/visitors 30m
/sales today
/conversations
/help
```

## 8.2 Controlled write commands

Later commands:

* Add internal order note
* Change conversation state
* Assign conversation
* Pause or resume an automation rule
* Change operator availability
* Modify an order state where explicitly allowed

Requirements:

* Telegram identity allowlist
* WordPress user association
* Role and capability checks
* Explicit confirmation
* Expiring action tokens
* Complete audit logging
* No arbitrary WordPress, SQL, shell or PHP execution

---

# 9. AI assistant

## 9.1 Operating modes

Per chat profile:

| Mode            | Behaviour                           |
| --------------- | ----------------------------------- |
| Disabled        | Human operators only                |
| Draft assistant | AI drafts; operator approves        |
| AI first        | AI replies within approved scope    |
| Automatic       | AI handles explicitly allowed cases |

The initial release should support **Draft assistant** only.

## 9.2 Provider abstraction

Support a provider-neutral interface:

* OpenAI
* Anthropic
* OpenAI-compatible endpoints
* Local model endpoints
* Disabled provider

Allow different models for:

* Classification
* Retrieval and answering
* Summarization
* Event analysis
* Draft generation

## 9.3 Approved knowledge

Possible sources:

* Selected WordPress pages
* FAQs
* Shipping and return policies
* Product information
* Public stock state
* Selected internal documentation
* Approved prior answers
* Limited verified order information

The administrator must explicitly select which content is eligible.

## 9.4 Retrieval and traceability

Record for every AI draft or response:

* Provider and model
* Knowledge sources used
* Prompt-policy version
* Tools invoked
* Human approval status
* Escalation reason
* Processing time and estimated cost where available

## 9.5 AI tools

Initial read-only tools:

* Search approved content
* Retrieve product information
* Check public stock status
* Retrieve shipping policy
* Summarize conversation
* Summarize recent events
* Draft response

Later controlled tools:

* Retrieve verified order
* Add internal order note
* Change conversation status
* Cancel an eligible order
* Create a restricted coupon

Write tools require capability checks and, normally, human confirmation.

## 9.6 AI safety

* Never invent product, medical, legal or shipping claims.
* Prefer approved content over general model knowledge.
* Escalate when sources are absent or contradictory.
* Disclose AI participation.
* Prevent prompt injection from site content and visitors.
* Separate system policy, retrieved content and customer input.
* Redact secrets and unnecessary personal data.
* Apply per-profile topic restrictions.
* Provide immediate human takeover.
* Never expose unrestricted database or code execution.

---

# 10. Administration interface

Primary sections:

1. **Overview**
2. **Automations**
3. **Events**
4. **Conversations**
5. **Chat Profiles**
6. **Telegram**
7. **AI**
8. **Templates**
9. **Privacy**
10. **Diagnostics**
11. **Tools**

Important interface features:

* Guided Telegram bot setup
* Rule builder
* Rule simulation
* Message preview
* Chat widget preview
* Delivery and queue health
* Conversation search
* Event filtering
* AI usage and approval history
* Configuration export/import
* System-status report with secrets removed

---

# 11. Security and privacy

## 11.1 Security controls

* Verify Telegram webhook secret
* Authenticate website chat requests
* Use nonces and rate limits
* Protect conversation tokens
* Encrypt or externally configure provider credentials
* Restrict settings by WordPress capabilities
* Sanitize custom CSS and templates
* Validate attachment types
* Prevent Telegram message injection
* Protect against replay
* Audit all privileged actions
* Avoid secrets in logs and exports

## 11.2 Privacy controls

* No raw IP transmission by default
* Configurable IP hashing or complete omission
* Anonymous session identifiers
* Configurable event and conversation retention
* Data export and deletion
* Consent-aware browser tracking
* Sensitive-field redaction
* AI-provider data-sharing disclosure
* Separate controls for analytics and essential chat
* Explicit warnings for templates containing personal data

## 11.3 Operational separation

Event monitoring, customer chat and AI must be independently enabled. An administrator may use Telegram notifications without enabling visitor tracking, chat or AI.

---

# 12. Performance and reliability

* Action Scheduler or an equivalent durable queue
* No Telegram or AI call during critical frontend execution
* Event batching
* Rate limiting per rule and destination
* Deduplication windows
* Circuit breakers for failing providers
* Retry with exponential backoff
* Dead-letter handling
* Queue-health alerts
* Database indexes for event and conversation queries
* Configurable sampling of high-volume events
* Cached rules and compiled widget configuration
* Background retention cleanup

The plugin must remain safe if Telegram or the AI provider is unavailable.

---

# 13. Milestone roadmap

## M0 — Product foundation

**Objective:** Establish architecture and quality boundaries.

Deliverables:

* Plugin bootstrap and module boundaries
* Coding standards and automated tests
* Database migration framework
* Capability model
* Queue abstraction
* Audit logging
* Privacy classification model
* Architecture decisions
* Developer documentation

Validation:

* Clean install, upgrade and uninstall
* Queue failure does not affect frontend requests
* Secrets excluded from logs
* Unit and integration test foundations pass

## M1 — Telegram connectivity

**Objective:** Provide reliable bidirectional Telegram communication.

Deliverables:

* Bot configuration
* Multiple destinations
* Connection testing
* Outbound queue
* Inbound webhook
* Forum topic support
* Retry, rate limiting and delivery log
* Diagnostics

Validation:

* Send and receive test messages
* Validate webhook authenticity
* Recover from temporary Telegram failures
* Confirm no token reaches browser output

## M2 — Normalized events and notifications

**Objective:** Replace fixed notification checkboxes with configurable rules.

Deliverables:

* Event model and registry
* Core WordPress events
* Rule engine
* AND condition groups
* Telegram action
* Message templates
* Deduplication and cooldown
* Event history
* Rule simulation

Validation:

* Deterministic rule evaluation
* No duplicate delivery across retries
* Clear explanation of matched and rejected rules

## M3 — WooCommerce event coverage

**Objective:** Cover the commerce journey reliably.

Deliverables:

* Product, cart, checkout, payment, order and stock events
* HPOS compatibility
* Classic and block compatibility
* Failure and validation context
* Sensitive-field redaction

Validation:

* Classic and block checkout tests
* Order events work under HPOS
* Telegram failures cannot affect checkout
* Server and browser events do not double-count

## M4 — Visitor and browser events

**Objective:** Capture configurable frontend activity, including cached pages.

Deliverables:

* Lightweight tracking client
* Page and product views
* Navigation and configurable click events
* Search and funnel events
* JavaScript error reporting
* Consent integration
* Bot filtering
* Batching and sampling

Validation:

* Works behind full-page caching
* Respects disabled or denied tracking
* Bounded request and storage overhead

## M5 — Conversation backend

**Objective:** Establish secure persistent conversations.

Deliverables:

* Conversation and message models
* Secure visitor tokens
* Telegram topic creation
* Bidirectional routing
* Status and assignment
* Reconnection
* Retention controls

Validation:

* Parallel conversations remain isolated
* Navigation does not lose conversation
* Unauthorized visitors cannot read another conversation
* Telegram replies reach the correct visitor

## M6 — Configurable chat widget

**Objective:** Deliver the complete administrator-configurable frontend chat.

Deliverables:

* Floating and inline modes
* Open, close, minimize and reopen behaviour
* Desktop and mobile placement
* Chat profiles and targeting
* Business hours
* Pre-chat form
* Visual controls
* Scoped custom CSS
* Live preview
* Localization
* Accessibility compliance

Validation:

* Keyboard and screen-reader acceptance
* Responsive viewport matrix
* Theme-conflict tests
* Profile-priority tests
* Closed and minimized state tests
* Cached-page compatibility

## M7 — Operator workflow

**Objective:** Make Telegram practical as a support console.

Deliverables:

* Operator identity mapping
* Assignment
* Conversation status controls
* Online, busy and offline state
* Notifications and unread state
* Internal notes
* Resolution and reopening
* Conversation search in WordPress

Validation:

* Unauthorized Telegram users cannot act
* Operator actions are audited
* Multiple operators cannot silently overwrite state

## M8 — Administrative bot

**Objective:** Provide secure operational queries from Telegram.

Deliverables:

* Command registry
* Read-only status, order, stock, error and sales commands
* Capability mapping
* Confirmation framework
* Auditing
* Extension API

Validation:

* Permission matrix
* Invalid and replayed commands rejected
* Sensitive fields redacted

## M9 — AI draft assistant

**Objective:** Assist operators without autonomous customer responses.

Deliverables:

* Provider abstraction
* Model configuration
* Approved-content retrieval
* Conversation summaries
* Draft answers
* Source traceability
* Human approval workflow
* Cost and error reporting
* Prompt-injection defences

Validation:

* No customer receives unapproved drafts
* Unsupported questions escalate
* Sources and provider calls are traceable
* Sensitive data is excluded according to policy

## M10 — Controlled AI responses

**Objective:** Permit narrowly scoped AI-first chat.

Prerequisite:

M9 must demonstrate acceptable quality and safety.

Deliverables:

* Per-profile AI policies
* Confidence and escalation rules
* Restricted read-only tools
* Human takeover
* Response limits
* AI disclosure
* Evaluation suite

Validation:

* Adversarial prompt tests
* Unsupported health and policy questions escalate
* No unauthorized tool invocation
* Human takeover is immediate

## M11 — Digests and operational intelligence

**Objective:** Turn high-volume events into useful summaries.

Deliverables:

* Scheduled summaries
* Threshold alerts
* Checkout-failure detection
* Funnel summaries
* Error clustering
* AI-assisted internal summaries
* Destination-specific reporting

Validation:

* High event volumes do not flood Telegram
* Aggregates reconcile with retained events
* Alert thresholds are deterministic

## M12 — Hardening and release

**Objective:** Prepare for dependable production use.

Deliverables:

* Load and concurrency testing
* Migration and rollback testing
* Security review
* Privacy review
* Accessibility review
* Multisite assessment
* Import/export
* Support diagnostics
* Administrator and developer documentation
* Release packaging

Validation:

* Production-scale queue tests
* Upgrade from every supported schema
* Failure injection for Telegram and AI providers
* Clean uninstall and configurable data retention
* Final acceptance matrix passes

---

# 14. Recommended v1.0 boundary

Version 1.0 should include M0–M7:

* Telegram connectivity
* Event and automation engine
* WordPress and WooCommerce events
* Visitor tracking
* Bidirectional customer chat
* Configurable chat profiles
* Floating and inline widget
* Placement and behaviour settings
* Custom CSS
* Telegram operator workflow
* Privacy, diagnostics and audit logging

AI and administrative write commands should not block v1.0. They introduce separate security and quality risks and should follow after the non-AI foundation is stable.

---

# 15. Initial default configuration

On first activation:

* No tracking enabled automatically
* Telegram setup wizard displayed
* Notifications disabled until destination validation
* Chat disabled until explicitly configured
* Floating widget at bottom right
* Initially closed
* Closable and reopenable
* State remembered for the browser session
* No sound
* No automatic opening
* Mobile near-full-screen panel
* No AI
* Essential chat storage only
* Short configurable retention
* Sensitive Telegram fields redacted
* Draft example rules provided but not activated

---

# 16. Success criteria

The project succeeds when an administrator can:

1. Connect Telegram without editing code.
2. Define precisely when notifications should be sent.
3. Target rules and chat profiles to specific site contexts.
4. Communicate with website visitors entirely from Telegram.
5. Customize widget appearance and behaviour safely.
6. Operate without flooding Telegram.
7. Diagnose delivery and conversation failures.
8. Add custom plugin events through a stable API.
9. Enable AI gradually without coupling the plugin to one provider.
10. Maintain visitor privacy and ensure external failures never disrupt commerce.
