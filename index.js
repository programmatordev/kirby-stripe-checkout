panel.plugin("programmatordev/stripe-checkout", {
  components: {
    "k-stripe-checkout-overview-view": {
      props: {
        description: String,
        items: Array,
        tabs: Array,
        title: String,
      },
      template: `
        <k-panel-inside>
          <k-header>{{ title }}</k-header>
          <k-tabs :tabs="tabs" tab="overview" />
          <k-box :text="description" theme="info" />
          <k-section>
            <k-items :items="items" />
          </k-section>
        </k-panel-inside>
      `,
    },
    "k-stripe-checkout-setup-view": {
      props: {
        action: String,
        canSetup: Boolean,
        description: String,
        dialog: String,
        tabs: Array,
        title: String,
      },
      methods: {
        setup() {
          this.$panel.dialog.open(this.dialog);
        },
      },
      template: `
        <k-panel-inside>
          <k-header>{{ title }}</k-header>
          <k-tabs :tabs="tabs" tab="settings" />
          <k-empty icon="settings" :text="description">
            <k-button
              v-if="canSetup"
              icon="add"
              variant="filled"
              @click="setup"
            >{{ action }}</k-button>
          </k-empty>
        </k-panel-inside>
      `,
    },
    "k-stripe-checkout-diagnostics-view": {
      props: {
        checks: Array,
        description: String,
        status: String,
        statusTheme: String,
        tabs: Array,
        title: String,
      },
      template: `
        <k-panel-inside>
          <k-header>{{ title }}</k-header>
          <k-tabs :tabs="tabs" tab="diagnostics" />
          <k-box :text="description" theme="info" />
          <k-section :headline="status">
            <k-items :items="checks" :theme="statusTheme" />
          </k-section>
        </k-panel-inside>
      `,
    },
  },
});
