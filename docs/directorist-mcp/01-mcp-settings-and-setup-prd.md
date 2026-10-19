# PRD 01 — MCP Settings and Setup

Status: proposed V1. Related: [overview](README.md), [access and safety](06-access-control-and-write-safety-prd.md), [tool catalog](07-mcp-tool-catalog.md).

## Outcome and current baseline

An administrator can deliberately expose Directorist tools and help each user connect with their own WordPress identity. Directorist will provide a feature card with an enable switch and a conditional Configure button. Configure opens a right-side MCP Connection modal containing the connection setup. The existing settings panel does not have a dedicated field supporting this complete interaction; implement a new MCP configuration field within the existing Vue settings shell and WordPress authentication model.

## Functional requirements

- Add **Other Features** as a new root-level item in the Directorist Settings sidebar, with **MCP** beneath it. The location is **Directorist Settings → Other Features → MCP**, not a new WordPress admin root menu or a separate root-level MCP settings item. Keep existing settings in their current locations.
- Render the MCP feature card through a new dedicated settings field type, `mcp-configuration`, bound to the additive `mcp_enabled` key in `atbdp_option`, default `false`. The field owns the title, description, status, enable switch, conditional Configure action, and connection drawer. A generic toggle plus a separate generic button is insufficient.
- Persist switch changes immediately through the existing nonce-protected settings-save service, submitting only `mcp_enabled`. Do not require the panel's general Save changes button. Update the field's current and cached values from the confirmed persisted result; failed saves restore the last confirmed state and show an error. Preserve unrelated unsaved edits and existing update hooks.
- Show **Configure** only when the confirmed persisted MCP state is enabled. Hide it while disabled; an unsaved or failed enable action must not expose it. Disable switch/action interaction during a pending toggle request. Clicking Configure opens the connection drawer without navigation or page reload.
- The feature remains off after upgrade. Missing Abilities API or adapter blocks enablement and explains recovery; if a dependency later disappears, stored opt-in can remain true but the server must reject execution until readiness returns.
- Use the installed compatible WordPress MCP Adapter. Link to manual installation/activation guidance; do not silently install, activate, or update a plugin. Detect incompatible providers and display their actionable compatibility failure.
- Show settings only to `manage_options` users. Provide a public, credential-free setup guide that owners can receive as a link without gaining settings access. Application-password creation/revocation remains in the user's WordPress profile.

### UI states

| State | Conditions | User-facing behavior |
|---|---|---|
| Dependency missing/incompatible | Abilities API or adapter unavailable | Show Setup required and remedy; block enable action; no tool execution. If previously enabled, retain Configure so the drawer can explain recovery; do not display usable snippets. |
| Disabled | Dependencies ready, saved switch off | Show Inactive with the switch off; Configure is hidden; show no inline connection form. |
| Saving | Enable/disable request pending | Show toggle loading state; prevent duplicate changes and opening Configure; do not claim the new state is saved. |
| Ready to connect | Saved switch on and prerequisites pass | Show Active, the switch on, and Configure; endpoint and setup are inside the drawer. Active means MCP is enabled, not that a remote client is connected. |
| Connection verified | User completed the client smoke test | Guide explains expected identity and permissions; no persistent claim that every client remains connected |
| Connection error | A setup/diagnostic step fails | Distinguish authentication, authorization, TLS, endpoint, dependency, and protocol failures |

Readiness checks include WordPress 6.9+ functions, compatible adapter APIs, HTTPS, REST reachability, and whether WordPress makes application passwords available. Production guidance requires trusted HTTPS. Local development guidance uses a trusted local certificate; never generate TLS-verification bypass instructions.

### Dedicated field and connection drawer

| Element | Required behavior |
|---|---|
| Navigation | Root layout key `other_features`, MCP submenu key `mcp`; breadcrumb Settings / Other Features / MCP. Support existing navigation/deep-link and settings-search conventions without an inline generic toggle editor bypass. |
| Field registration | PHP field key `mcp_enabled`, type `mcp-configuration`; dedicated Vue `McpConfigurationField` component registered through the settings registry; the implementation ships in `assets/js/directorist-mcp-settings.js`. Preserve the existing scalar boolean storage, field/layout/config envelope, permission gate and nonce handling. |
| Feature card | Title MCP for AI Agents, short Directorist-specific description, status badge and right-aligned action area. Configure precedes the enable switch when visible. Do not duplicate endpoint/credentials on the card. |
| Drawer | Right-side modal titled MCP Connection; 640px desktop width capped at the viewport width, full width on small screens; background overlay, fixed header/close control and independently scrolling body. Render outside clipped settings scroll containers with correct stacking. |
| Connection summary | Readiness/dependency feedback, available Directorist tool count and group summary, read-only Endpoint URL with Copy, setup/help link and guidance for creating/revoking a personal application password. |
| Credentials | Username prefilled with the current user's login and editable locally; masked application-password input with an explicit show/hide control. No normal WordPress login-password request and no automatic credential creation. |
| Client selection | Tabs for Claude Code, Claude Desktop, Cursor, Codex and Other, with Claude Code selected initially. Each tab has its instructions and a read-only copyable snippet. Preserve local credentials when switching tabs in the same open drawer. Release support still requires verified client/bridge versions. |
| Snippet handling | Fetch server templates containing placeholders only. Fill credentials in browser memory for display/copy; header-based examples encode username plus application password locally. Without both inputs, retain placeholders and explain what remains to fill. Copy snippet copies only the selected tab and reports success or an actionable clipboard failure. |
| Loading/errors | Opening fetches fresh status; lazily fetch snippet templates when enabled and ready. Show scoped loading and retry states without hiding the drawer. Never show stale credential-bearing snippets if the server becomes unavailable. |
| Close/cleanup | Close button, Escape or overlay closes the drawer; closing never changes enablement or saves credentials. Clear the password and rendered credential-bearing snippets on close, navigation, permission loss and component destruction. Reopening starts without a password. |
| Accessibility | Named modal dialog with modal semantics; focus enters the drawer, stays trapped while open, and returns to Configure on close. Background is inert; controls and tabs are keyboard accessible; loading/copy/error feedback is announced. |

The field's durable value is only `mcp_enabled`. Drawer visibility, active tab, username input, password, loading state, status payload, and snippets are transient component state. Keep credentials out of the shared Vuex settings data, cached settings fields, local/session storage, URLs, requests, logs, analytics, exports and general form submissions. An explicit user Copy snippet action may place a locally completed credential-bearing snippet on the clipboard; explain that the copied configuration contains a secret. Closing the drawer clears memory but cannot revoke or erase a copy the user has made.

The field must not participate as a stale generic boolean in a later full-panel Save changes payload. After its targeted save, synchronize current/cached state so the panel does not show MCP as unsaved. If other fields are already dirty, preserve their values and dirty indicators. Configure, tab changes and copy actions never mark settings dirty. If quick search finds this field, route to Other Features → MCP and focus the dedicated field instead of rendering a generic toggle editor.

If another session disables MCP or removes prerequisites while the drawer is open, refresh/recheck on status fetch, window refocus and configuration retry. On confirmed disable, close the drawer, clear credential state and hide Configure. On dependency loss with saved enablement retained, keep the drawer open in Setup required state, clear snippets and provide recovery guidance. All server requests still enforce current enablement/permissions independently of the UI.

## User stories and acceptance criteria

### MCP-01 — Enable or disable the server

As a site administrator, I want to control MCP availability so that external tools can access Directorist only after I opt in.

- **MCP-01-AC1:** Given an existing or fresh site with no saved MCP preference, when settings load, then MCP is off and no Directorist ability can execute through any entry point.
- **MCP-01-AC2:** Given a `manage_options` user and ready prerequisites, when the user turns on the enable switch, then an immediate targeted save changes only the MCP preference, a fresh read reports the saved state, and Configure appears after success; the general Save changes action is not required. A failed save leaves the UI off with an error.
- **MCP-01-AC3:** Given an enabled server, when the administrator disables it, then subsequent tool calls and old previews are rejected, including calls through other adapter servers or the Abilities API.

### MCP-02 — Understand readiness and dependencies

As a site administrator, I want a clear readiness checklist so that I can fix prerequisites before troubleshooting a client.

- **MCP-02-AC1:** Given WordPress without the required Abilities API or an unavailable/incompatible adapter, when opening MCP settings, then the missing dependency is named and ordinary Directorist pages continue working without fatal errors.
- **MCP-02-AC2:** Given a previously enabled installation whose adapter is deactivated, when status is refreshed, then the page reports dependency failure rather than “ready”; execution remains blocked.
- **MCP-02-AC3:** Given failed HTTPS or disabled application passwords, when viewing setup, then the relevant failed check and recovery instructions appear without revealing credentials or offering a TLS bypass.

### MCP-03 — Connect using a personal application password

As an authorized user, I want setup steps and compatible configuration examples so that my assistant acts as my own WordPress account.

- **MCP-03-AC1:** Given a ready server, when following the guide below with a compatible HTTP client, then initialization, tool discovery, and `directorist-get-context` succeed under the supplied user's identity.
- **MCP-03-AC2:** Given a client needing a local bridge, when using a documented bridge version that supports custom endpoint/application-password authentication, then the same read-only smoke test succeeds; required runtime and credential handling are explained.
- **MCP-03-AC3:** Given server-generated snippet templates, when inspected, then they contain placeholders only; entering credentials fills snippets locally without sending the application password to Directorist or storing it in settings, shared state, analytics or diagnostic logs.
- **MCP-03-AC4:** Given credentials entered locally in the open drawer, when the user explicitly copies the selected client's snippet, then the completed snippet is copied with visible feedback; switching tabs preserves the input only for that open drawer, and closing/reopening clears the password and completed snippets.

### MCP-04 — Verify a connection and diagnose failures

As a connecting user, I want a read-only smoke test and specific errors so that I can distinguish setup problems from missing permissions.

- **MCP-04-AC1:** Given valid credentials, when running initialization, discovery, and context inspection, then results show the current user ID, safe identity label, effective tool groups, and permitted directory references without changing product data.
- **MCP-04-AC2:** Given an invalid/revoked application password, when connecting, then authentication fails without returning a tool result or protected identity data; a valid user without Directorist access receives an authorization failure.
- **MCP-04-AC3:** Given an unsupported transport/protocol, wrong URL, or blocked Authorization header, when troubleshooting, then the guide distinguishes the failure and provides a next step rather than recommending broader user permissions as a universal fix.

### MCP-05 — Revoke a client connection

As a WordPress user, I want to revoke a client's application password so that the client can no longer act as me.

- **MCP-05-AC1:** Given a named application password, when revoked from WordPress, then subsequent requests using it fail; an adapter session must not preserve authorization after credential revocation.
- **MCP-05-AC2:** Given two separately named passwords for a user, when one is revoked, then the other continues working within the user's current capabilities.
- **MCP-05-AC3:** Given saved connection examples and troubleshooting instructions, when the guide is read, then it explains that disabling Directorist MCP does not revoke application passwords or prevent their use against other permitted WordPress APIs.

### MCP-06 — Onboard a listing owner without configuration access

As a listing owner, I want a setup guide and permission summary so that I can manage my listings without accessing site configuration.

- **MCP-06-AC1:** Given an owner with eligible listing capabilities, when following the shared guide and connecting, then the owner can discover allowed listing operations and assignment references but cannot read settings or builder configuration.
- **MCP-06-AC2:** Given an owner without `manage_options`, when requesting the MCP administration UI or control endpoints directly, then access is denied even if the owner knows their URLs.
- **MCP-06-AC3:** Given a site policy that prevents owners from creating application passwords, when the owner reads the guide, then it explains that a site administrator must resolve that policy; no shared administrator credential is recommended.

### MCP-07 — Find MCP under Other Features

As a site administrator, I want MCP under a root-level Other Features settings menu so that I can find optional capabilities in one consistent place.

- **MCP-07-AC1:** Given the Directorist settings sidebar, when opening Other Features → MCP, then the dedicated MCP field appears with the correct breadcrumb and no separate root-level MCP menu or new WordPress admin root menu.
- **MCP-07-AC2:** Given a direct navigation link or an MCP settings-search result, when opened, then navigation resolves to Other Features → MCP and focuses the dedicated field without duplicating it or offering a generic inline toggle editor.
- **MCP-07-AC3:** Given existing settings navigation and a user without `manage_options`, when testing the new route, then existing locations remain unchanged and the unauthorized user cannot access the MCP field, metadata or connection drawer.

### MCP-08 — Use a dedicated configuration field

As a site administrator, I want one MCP settings field to manage enablement and configuration access so that saved state and available actions remain consistent.

- **MCP-08-AC1:** Given the registered field schema, when MCP settings render, then `mcp_enabled` uses the new `mcp-configuration` field component containing the feature card, badge, switch and drawer action rather than separate generic controls.
- **MCP-08-AC2:** Given disabled MCP, when enabling succeeds or fails, then Configure appears only after confirmed success; repeated toggle requests are blocked during saving and failure restores the confirmed state with feedback.
- **MCP-08-AC3:** Given unrelated unsaved settings, when enabling/disabling MCP and later saving the other settings, then only the intended values persist, the generic save cannot overwrite MCP with stale data, and unrelated dirty indicators remain accurate.
- **MCP-08-AC4:** Given the field after reload or an external state change, when status is refreshed, then the badge, switch and Configure visibility reflect persisted state; opening/configuring/copying alone never marks settings unsaved.

### MCP-09 — Configure through an accessible side panel

As a site administrator, I want Configure to open a connection side panel so that I can prepare a client connection without leaving the settings page.

- **MCP-09-AC1:** Given enabled MCP, when clicking Configure, then a right-side MCP Connection modal opens without reload, showing endpoint copy, tool summary, profile guidance, local credential inputs, client tabs and selected-client snippet; loading/failure states offer a retry.
- **MCP-09-AC2:** Given the open panel at desktop or mobile width, when using keyboard navigation, closing by button/Escape/overlay or scrolling long content, then the 640px-capped responsive drawer remains usable, background is inert, focus stays trapped and returns to Configure when it remains available.
- **MCP-09-AC3:** Given a selected client and locally entered credentials, when switching tabs or copying endpoint/snippet, then instructions and snippets match that client, endpoint copy contains only the URL, and clipboard failures show feedback without falsely reporting success.
- **MCP-09-AC4:** Given the panel with a password, when closed, navigated away from or access is lost, then secret input and completed snippets are cleared from component memory and never included in settings saves, status/template requests, URLs, storage or logs.
- **MCP-09-AC5:** Given another session disables MCP or removes its adapter, when the open panel refreshes status, then disable closes/clears the panel and hides Configure, while dependency loss shows Setup required with no usable stale snippets.

## Setup guide to ship

1. An administrator checks the supported WordPress/adapter versions, trusted HTTPS, REST availability, and application-password availability; installs/activates the adapter through normal WordPress administration if needed; and turns on MCP under Directorist Settings → Other Features → MCP. After the targeted save succeeds, click Configure to open the MCP Connection side panel.
2. The connecting user signs into their own WordPress account, opens Profile → Application Passwords, creates a password with a recognizable client name, and saves it in the client's secure credential facility. Prefer separate credentials per client.
3. In the side panel, copy the exact endpoint generated by the site, enter the username and application password locally, and choose the target client tab. For a conventional installation the endpoint is `https://example.com/wp-json/directorist/mcp`; do not construct it by appending to an assumed domain root.
4. Configure a compatible Streamable HTTP client with that endpoint and an `Authorization: Basic <base64(username:application-password)>` header. Base64 is an encoding, not encryption; use HTTPS and treat the header as a secret. Do not use the user's normal login password.
5. For a client that requires a local bridge, follow the release-verified bridge example: MCP endpoint, WordPress username, application password, and OAuth disabled where the bridge supports that mode. Document the bridge's exact tested version and required runtime at implementation release; do not publish an unverified `latest` command as a supported setup.
6. Initialize MCP with a version supported by the installed adapter, complete its notification/session requirements, list tools, and call `directorist-get-context`. A successful HTTP response alone is not a complete smoke test.
7. Confirm the returned user and allowed operations, perform a permitted read, and use a disposable draft listing only when intentionally testing writes.
8. To disconnect one client, remove its configuration and revoke its named application password. To disable all Directorist MCP activity, the administrator turns the server off.

### Configuration example and compatibility boundary

This is a **generic connection specification**, not a promise that every client uses this JSON shape:

```json
{
  "name": "directorist",
  "transport": "streamable-http",
  "url": "https://example.com/wp-json/directorist/mcp",
  "headers": {
    "Authorization": "Basic <BASE64_USERNAME_AND_APPLICATION_PASSWORD>"
  }
}
```

The published guide must include a tested client-specific rendering of this connection and one tested local-bridge rendering, with names/versions and verification date. Application-password mode is supported for clients/bridges that can supply that authentication. V1 does not implement MCP OAuth discovery or claim compatibility with OAuth-only hosted connectors. See [WordPress authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) and the separate [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

## Native UI integration contract

The dedicated field needs authenticated, `manage_options`-protected status and snippet-template reads. Status returns saved enablement, dependency/readiness flags, generated endpoint, available tool count/groups, current user login and profile/help URLs. Templates return supported client IDs, instructions, placeholder snippets and verified version metadata. Neither request accepts an application password or emits a completed credential-bearing snippet.

Toggle persistence continues through `save_settings_data` with nonce validation and an explicit field list containing only `mcp_enabled`; validate/normalize the boolean and report the actual persisted result. Preserve the existing general save payload format. Configuration status/template reads remain native admin services and do not add MCP tools. Under PRDs 10/11, counts and groups must derive from the current eligible registry, including Orders and registered extension contributions. Field-specific UI, rendering, state synchronization and general-save exclusion are additive settings-panel work; implementing a dedicated field is required, not an assumption that the present generic controls already support this experience.

| Failure | Recovery guidance |
|---|---|
| Endpoint missing or HTML response | Re-copy generated URL; check REST routing, dependency status, and proxy/security rules |
| Authentication failure | Check username, application password, revocation, HTTPS, and Authorization-header forwarding |
| Authorization failure | Inspect the user's effective Directorist capabilities; an authenticated account is not automatically authorized |
| TLS failure | Install/trust the correct certificate; never disable verification in production instructions |
| Protocol/session failure | Use an adapter-supported protocol and honor initialization, negotiated headers, and session handling |
| Some tools missing | Check effective permissions, extension availability, and server state; do not silently elevate the user |

## Dependencies and release verification

The MCP controls use authenticated WordPress admin requests with nonce validation and `manage_options`. They are not remote MCP tools. All feature stories additionally depend on [SEC-01 through SEC-07](06-access-control-and-write-safety-prd.md). See [setup test scenarios](08-acceptance-test-matrix.md) for release checks; client/bridge runtime compatibility remains unverified until those checks run.
