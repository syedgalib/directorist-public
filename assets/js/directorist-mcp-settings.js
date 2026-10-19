/* Directorist's dedicated MCP field, delivered through the settings registry. */
(function () {
  "use strict";
  const config = window.directoristMcpSettings;
  if (!config) return;

  const component = {
    name: "McpConfigurationField",
    props: {
      value: { default: false },
      label: String,
      description: String,
      fieldKey: String,
    },
    data() {
      return {
        enabled: this.value === true || this.value === "1",
        status: null,
        loading: false,
        saving: false,
        opened: false,
        clients: [],
        client: "claude-code",
        templatesLoading: false,
        username: "",
        password: "",
        showPassword: false,
        message: "",
        error: "",
        templatesError: "",
        headingId: "directorist-mcp-" + this._uid,
      };
    },
    computed: {
      ready() {
        return Boolean(this.status && this.status.ready);
      },
      badge() {
        return this.loading && !this.status
          ? "Checking setup…"
          : !this.ready
            ? "Setup required"
            : this.enabled
              ? "Active"
              : "Inactive";
      },
      selectedClient() {
        return this.clients.find((item) => item.id === this.client) || null;
      },
    },
    created() {
      this._alive = true;
      this._requests = new Set();
      this.refresh();
      this._refocus = () => {
        if (this.opened) this.refresh();
      };
      window.addEventListener("focus", this._refocus);
      this._navigation = () => this.close(false);
      window.addEventListener("hashchange", this._navigation);
      window.addEventListener("pagehide", this._navigation);
    },
    beforeDestroy() {
      this._alive = false;
      this.close(false);
      this._requests.forEach((controller) => controller.abort());
      window.removeEventListener("focus", this._refocus);
      window.removeEventListener("hashchange", this._navigation);
      window.removeEventListener("pagehide", this._navigation);
      if (this.$refs.overlay && this.$refs.overlay.parentNode === document.body)
        this.$refs.overlay.remove();
    },
    methods: {
      async request(url, options = {}) {
        const controller = new AbortController();
        this._requests.add(controller);
        try {
          const response = await fetch(url, {
            credentials: "same-origin",
            ...options,
            headers: { "X-WP-Nonce": config.nonce, ...(options.headers || {}) },
            signal: controller.signal,
          });
          const data = await response.json();
          if (!response.ok) {
            if (response.status === 401 || response.status === 403)
              this.close(false);
            throw new Error(
              data.message || "The request could not be completed.",
            );
          }
          return data;
        } finally {
          this._requests.delete(controller);
        }
      },
      persist(value) {
        this.enabled = Boolean(value);
        this.$store.commit("setPersistedAsyncField", {
          field_key: this.fieldKey || "mcp_enabled",
          value: this.enabled,
        });
        this.$store.commit("updateFieldData", {
          field_key: this.fieldKey || "mcp_enabled",
          option_key: "forceUpdate",
          value: false,
        });
      },
      async refresh() {
        this.loading = true;
        this.error = "";
        try {
          const status = await this.request(config.statusUrl);
          if (!this._alive) return;
          this.status = status;
          this.persist(status.enabled);
          if (!this.username) this.username = status.current_user_login || "";
          if (!status.enabled && this.opened) this.close();
          else if (!status.ready) this.clients = [];
        } catch (error) {
          if (this._alive && error.name !== "AbortError")
            this.error = error.message;
        } finally {
          if (this._alive) this.loading = false;
        }
      },
      async toggle(next) {
        if (this.saving || this.loading) return;
        this.saving = true;
        this.error = "";
        this.message = "";
        try {
          const body = new FormData();
          body.append("action", "save_settings_data");
          body.append("directorist_nonce", config.ajaxNonce);
          body.append("field_list", JSON.stringify(["mcp_enabled"]));
          body.append("mcp_enabled", JSON.stringify(Boolean(next)));
          const response = await this.request(config.ajaxUrl, {
            method: "POST",
            body,
          });
          if (!response.status || !response.status.success)
            throw new Error(
              response.status?.status_log?.message ||
                "The setting could not be saved.",
            );
          await this.refresh();
          if (this.enabled !== Boolean(next))
            throw new Error(
              "The saved MCP state did not match the requested change. Please retry.",
            );
          this.message = this.enabled
            ? "MCP enabled. Configure a client to connect."
            : "MCP disabled.";
        } catch (error) {
          if (this._alive && error.name !== "AbortError")
            this.error = error.message;
        } finally {
          if (this._alive) this.saving = false;
        }
      },
      async open() {
        if (!this.enabled || this.saving || this.loading) return;
        this.opened = true;
        this.message = "";
        this.password = "";
        this.client = "claude-code";
        await this.$nextTick();
        if (!this.opened) return;
        this._returnFocus = document.activeElement;
        document.body.appendChild(this.$refs.overlay);
        this._background = Array.from(document.body.children)
          .filter(
            (node) =>
              node !== this.$refs.overlay &&
              !["SCRIPT", "STYLE", "LINK"].includes(node.tagName),
          )
          .map((node) => ({ node, inert: node.hasAttribute("inert") }));
        this._background.forEach((item) => item.node.setAttribute("inert", ""));
        this._bodyOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        this.$refs.close.focus();
        await this.refresh();
        if (this.opened && this.ready) await this.loadTemplates();
      },
      close(restoreFocus = true) {
        this.password = "";
        this.username = this.status ? this.status.current_user_login || "" : "";
        this.showPassword = false;
        this.clients = [];
        this.templatesError = "";
        this.opened = false;
        if (this._background) {
          this._background.forEach((item) => {
            if (!item.inert) item.node.removeAttribute("inert");
          });
          this._background = null;
          document.body.style.overflow = this._bodyOverflow || "";
        }
        if (restoreFocus && this._alive)
          this.$nextTick(() => {
            const target = this.enabled
              ? this.$refs.configure
              : this.$refs.root;
            if (target && target.isConnected) target.focus();
          });
      },
      async loadTemplates() {
        if (!this.opened || !this.ready || !this.enabled) return;
        this.templatesLoading = true;
        this.templatesError = "";
        try {
          const response = await this.request(config.templatesUrl);
          if (this._alive && this.opened && this.ready && this.enabled)
            this.clients = response.clients || [];
        } catch (error) {
          if (this._alive && error.name !== "AbortError")
            this.templatesError = error.message;
        } finally {
          if (this._alive) this.templatesLoading = false;
        }
      },
      snippet() {
        if (
          !this.opened ||
          !this.ready ||
          !this.enabled ||
          !this.selectedClient
        )
          return "";
        const template = this.selectedClient.snippet;
        if (!this.username.trim() || !this.password.trim()) return template;
        const username = this.username.trim();
        const password = this.password.trim();
        const bytes = new TextEncoder().encode(username + ":" + password);
        const basic = btoa(
          Array.from(bytes, (byte) => String.fromCharCode(byte)).join(""),
        );
        const replace = (text) =>
          text
            .split("<base64(username:application-password)>")
            .join(basic)
            .split("<username>")
            .join(username)
            .split("<application-password>")
            .join(password);
        try {
          const walk = (value) => {
            if (typeof value === "string") return replace(value);
            if (Array.isArray(value)) return value.map(walk);
            if (value && typeof value === "object")
              return Object.fromEntries(
                Object.entries(value).map(([key, item]) => [key, walk(item)]),
              );
            return value;
          };
          return JSON.stringify(walk(JSON.parse(template)), null, 2);
        } catch (_) {
          return replace(template);
        }
      },
      async copy(text, label) {
        this.message = "";
        try {
          if (navigator.clipboard && window.isSecureContext)
            await navigator.clipboard.writeText(text);
          else {
            const input = document.createElement("textarea");
            input.value = text;
            input.setAttribute("readonly", "");
            this.$refs.dialog.appendChild(input);
            try {
              input.select();
              if (!document.execCommand("copy")) throw new Error();
            } finally {
              input.value = "";
              input.remove();
            }
          }
          if (this.opened) this.message = label + " copied to clipboard.";
        } catch (_) {
          if (this.opened)
            this.message =
              "Could not copy. Select the text and copy it manually.";
        }
      },
      keydown(event) {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          this.close();
          return;
        }
        if (event.key !== "Tab") return;
        const nodes = Array.from(
          this.$refs.dialog.querySelectorAll(
            'button:not([disabled]), a[href], input:not([disabled]), textarea, [tabindex="0"]',
          ),
        ).filter((node) => node.getClientRects().length);
        if (!nodes.length) {
          event.preventDefault();
          return;
        }
        const first = nodes[0],
          last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      },
      tabKey(event, index) {
        let next = index;
        if (event.key === "ArrowRight")
          next = (index + 1) % this.clients.length;
        else if (event.key === "ArrowLeft")
          next = (index - 1 + this.clients.length) % this.clients.length;
        else if (event.key === "Home") next = 0;
        else if (event.key === "End") next = this.clients.length - 1;
        else return;
        event.preventDefault();
        this.client = this.clients[next].id;
        this.$nextTick(() => this.$refs.tabs[next].focus());
      },
    },
    template: `
        <div class="directorist-mcp-field" ref="root" tabindex="-1">
            <div class="directorist-mcp-card">
                <div><div class="directorist-mcp-title"><h3>{{ label || 'MCP for AI Agents' }}</h3><span class="directorist-mcp-badge" :class="{ 'is-active': enabled && ready }">{{ badge }}</span></div><p>{{ description }}</p></div>
                <div class="directorist-mcp-actions"><button v-if="enabled" ref="configure" type="button" class="directorist-mcp-button" :disabled="saving || loading" @click="open">Configure</button>
                    <label class="directorist-mcp-switch"><input type="checkbox" role="switch" aria-label="Enable Directorist MCP" :checked="enabled" :disabled="saving || loading || (!ready && !enabled)" @change="toggle($event.target.checked)"><span aria-hidden="true"></span></label>
                    <span v-if="saving" role="status">Saving…</span>
                </div>
            </div>
            <p v-if="!ready && !loading" class="directorist-mcp-note">WordPress 6.9+ and an active compatible WordPress MCP Adapter are required. <a :href="status ? status.adapter_url : 'https://wordpress.org/plugins/mcp-adapter/'" target="_blank" rel="noopener noreferrer">Adapter setup</a></p>
            <p v-if="error" role="alert" class="directorist-mcp-error">{{ error }} <button type="button" class="directorist-mcp-link" @click="refresh">Retry</button></p>
            <p v-if="message && !opened" role="status">{{ message }}</p>
            <div v-if="opened" ref="overlay" class="directorist-mcp-overlay" @click.self="close()" @keydown="keydown">
                <section ref="dialog" class="directorist-mcp-drawer" role="dialog" aria-modal="true" :aria-labelledby="headingId">
                    <header><h2 :id="headingId">MCP Connection</h2><button ref="close" type="button" class="directorist-mcp-close" aria-label="Close MCP Connection" @click="close()">×</button></header>
                    <div class="directorist-mcp-drawer-body">
                        <p v-if="loading" role="status">Checking connection setup…</p>
                        <p v-if="error" role="alert" class="directorist-mcp-error">{{ error }} <button type="button" @click="refresh">Retry</button></p>
                        <div v-if="!ready" class="directorist-mcp-note"><h3>Setup required</h3><p>Install and activate a compatible WordPress MCP Adapter, then refresh. Configuration snippets are unavailable until setup is ready.</p><button type="button" class="directorist-mcp-button" @click="refresh">Refresh setup</button></div>
                        <template v-else-if="status">
                            <p><strong>{{ status.tools_count }}</strong> Directorist tools available to authorized accounts</p><p class="directorist-mcp-note">{{ status.groups.join(' · ') }}. Each action checks your WordPress permissions.</p>
                            <label class="directorist-mcp-label">Endpoint URL</label><div class="directorist-mcp-copy-row"><input aria-label="MCP endpoint URL" :value="status.endpoint_url" readonly><button type="button" class="directorist-mcp-button" @click="copy(status.endpoint_url, 'Endpoint URL')">Copy</button></div>
                            <div v-if="!status.https || !status.application_passwords_available" class="directorist-mcp-note"><p v-if="!status.https">Use a trusted HTTPS connection before supplying credentials.</p><p v-if="!status.application_passwords_available">Application passwords are unavailable for this user. Resolve the WordPress site policy before connecting.</p></div>
                            <h3>Connect a client</h3><p>Your application password stays in this open panel and is used only to prepare the snippet you copy. <a :href="status.profile_url" target="_blank" rel="noopener noreferrer">Create an application password</a> for your own WordPress user.</p>
                            <form class="directorist-mcp-credentials" autocomplete="off" @submit.prevent><label>WordPress username<input v-model="username" autocomplete="off" maxlength="256"></label><label>Application password<input v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" maxlength="512"></label><button type="button" class="directorist-mcp-link" @click="showPassword = !showPassword">{{ showPassword ? 'Hide password' : 'Show password' }}</button></form>
                            <p v-if="templatesLoading" role="status">Loading client configuration…</p><p v-if="templatesError" role="alert" class="directorist-mcp-error">{{ templatesError }} <button type="button" @click="loadTemplates">Retry</button></p>
                            <button v-if="!clients.length && !templatesLoading" type="button" class="directorist-mcp-button" @click="loadTemplates">Load client configuration</button>
                            <div v-if="clients.length" class="directorist-mcp-tabs" role="tablist" aria-label="MCP client"><button v-for="(item, index) in clients" :key="item.id" ref="tabs" type="button" role="tab" :id="headingId + '-tab-' + item.id" :aria-controls="headingId + '-client'" :aria-selected="client === item.id ? 'true' : 'false'" :tabindex="client === item.id ? 0 : -1" @click="client = item.id" @keydown="tabKey($event, index)">{{ item.label }}</button></div>
                            <div v-if="selectedClient" role="tabpanel" :id="headingId + '-client'" :aria-labelledby="headingId + '-tab-' + client"><p>{{ selectedClient.instructions }}</p><p v-if="!username.trim() || !password.trim()" class="directorist-mcp-note">Enter both values to fill the snippet locally, or replace its placeholders in your client.</p><pre class="directorist-mcp-snippet" tabindex="0">{{ snippet() }}</pre><button type="button" class="directorist-mcp-button is-primary" :disabled="!snippet()" @click="copy(snippet(), 'Connection snippet')">Copy snippet</button><p class="directorist-mcp-note">A filled snippet contains your application password. Keep it private. Closing this panel clears its password input; revoke credentials from your WordPress profile to disconnect a client.</p></div>
                            <details><summary>Verify the connection</summary><p>Initialize your compatible HTTP client, discover tools, then call directorist-get-context. Check your identity and permissions before making a change. OAuth-only clients are not supported.</p></details>
                        </template>
                        <p v-if="message" role="status" aria-live="polite" class="directorist-mcp-feedback">{{ message }}</p>
                    </div>
                </section>
            </div>
        </div>`,
  };

  function install(registry) {
    if (install.done) return;
    install.done = true;
    registry.registerComponent("McpConfigurationField", component);
    registry.registerAfterMount((app, builderData, context) => {
      const store = app.$store;
      const menu = builderData.layouts.other_features;
      if (!menu || !store.state.fields.mcp_enabled) return;
      const prune = (node) => {
        let removed = false;
        if (Array.isArray(node.fields) && node.fields.includes("mcp_enabled")) {
          node.fields = node.fields.filter((key) => key !== "mcp_enabled");
          removed = true;
        }
        ["sections", "submenu"].forEach((kind) => {
          Object.keys(node[kind] || {}).forEach((key) => {
            const child = node[kind][key];
            if (prune(child)) {
              removed = true;
              if (
                (Array.isArray(child.fields) && !child.fields.length) ||
                (child.sections && !Object.keys(child.sections).length) ||
                (child.submenu && !Object.keys(child.submenu).length)
              )
                context.Vue.delete(node[kind], key);
            }
          });
        });
        return removed;
      };
      Object.keys(store.state.layouts).forEach((key) => {
        const node = store.state.layouts[key];
        if (prune(node) && node.submenu && !Object.keys(node.submenu).length)
          context.Vue.delete(store.state.layouts, key);
      });
      const displayMenu = JSON.parse(JSON.stringify(menu));
      displayMenu.icon =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7m-3.5-3.5v7"/></svg>';
      context.Vue.set(store.state.layouts, "other_features", displayMenu);
      store.commit("prepareNav");
      const manager = app.$children[0];
      if (manager && manager.getSettingsSavePayload) {
        const original = manager.getSettingsSavePayload;
        manager.getSettingsSavePayload = function (onlyKeys) {
          const keys = (onlyKeys || Object.keys(this.fields)).filter(
            (key) => key !== "mcp_enabled",
          );
          return original.call(this, keys);
        };
        manager.updateCurrentPage();
      }
    });
  }
  if (window.DirectoristSettingsPanel) install(window.DirectoristSettingsPanel);
  else
    document.addEventListener(
      "directorist:settings-panel:registry-ready",
      (event) => install(event.detail.registry),
      { once: true },
    );
})();
