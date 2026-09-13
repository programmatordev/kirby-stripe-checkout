<template>
	<k-models-dialog
		v-bind="$attrs"
		:endpoint="endpoint"
		:empty="{ icon: 'tag', text: $t('programmatordev.stripe-checkout.taxCodes.emptyCatalogue') }"
		:fetch-params="{ catalogueRevision: refreshRevision }"
		:multiple="false"
		:value="value ? [value] : []"
		size="large"
		@cancel="$emit('cancel')"
		@fetched="$emit('fetched', $event)"
		@submit="$emit('submit', $event)"
	>
		<template #header>
			<k-stripe-checkout-catalogue-dialog-header
				:title="$t('programmatordev.stripe-checkout.taxCodes.dialogTitle')"
				:refresh-label="$t('programmatordev.stripe-checkout.taxCodes.refresh')"
				:refreshing="refreshing"
				@refresh="refresh"
			/>
		</template>
	</k-models-dialog>
</template>

<script>
export default {
	name: "TaxCodeDialog",
	inheritAttrs: false,
	props: { endpoint: String, value: String },
	emits: ["cancel", "fetched", "refreshed", "submit"],
	data() {
		return { refreshing: false, refreshRevision: 0 };
	},
	methods: {
		async refresh() {
			if (this.refreshing || this.$panel.dialog.isLoading) {
				return;
			}

			try {
				this.refreshing = true;
				const response = await this.$api.post(this.endpoint);
				this.$emit("refreshed", response);
				// Changing the native fetchParams prop reloads the current search.
				// The revision is a UI trigger, not server-owned catalogue state.
				this.refreshRevision++;

				if (response.catalogue?.status === "ready") {
					this.$panel.notification.success(this.$t("programmatordev.stripe-checkout.taxCodes.refreshSuccess"));
				}
			} catch (error) {
				this.$panel.error(error);
			} finally {
				this.refreshing = false;
			}
		}
	}
};
</script>
