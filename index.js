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
