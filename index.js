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
    "k-stripe-checkout-settings-view": {
      extends: "k-page-view",
      props: {
        areaTabs: Array,
      },
      template: `
        <k-panel-inside
          class="k-page-view k-stripe-checkout-settings-view"
          :data-id="id"
          :data-locked="isLocked"
          :data-template="blueprint"
        >
          <k-header class="k-page-view-header">
            {{ title }}
            <template #buttons>
              <k-view-buttons :buttons="buttons">
                <template #after>
                  <k-form-controls
                    :editor="editor"
                    :has-diff="hasDiff"
                    :is-locked="isLocked"
                    :is-processing="isSaving"
                    :modified="modified"
                    @discard="onDiscard"
                    @submit="onSubmit"
                  />
                </template>
              </k-view-buttons>
            </template>
          </k-header>
          <k-tabs :tabs="areaTabs" tab="settings" />
          <k-model-tabs
            :diff="diff"
            :tab="tab.name"
            :tabs="tabs"
          />
          <k-sections
            :blueprint="blueprint"
            :content="content"
            :empty="$t('page.blueprint', { blueprint: $esc(blueprint) })"
            :lock="lock"
            :parent="api"
            :tab="tab"
            @input="onInput"
            @submit="onSubmit"
          />
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
