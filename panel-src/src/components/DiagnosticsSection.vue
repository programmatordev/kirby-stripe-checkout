<script>
export default {
	mixins: ["section"],
	data() {
		return {
			isLoading: true,
			issue: null,
			requestingEndpoint: null,
			sections: [],
			summary: {
				headline: "",
				reports: []
			}
		};
	},
	computed: {
		displaySections() {
			// Derive button props here so request state updates already-loaded item actions reactively.
			return this.sections.map(section => ({
				...section,
				items: section.items.map(item => ({
					...item,
					buttons: this.itemButtons(item)
				}))
			}));
		}
	},
	watch: {
		timestamp() {
			// Kirby signals model updates through the section timestamp; custom sections reload their own data.
			this.fetch();
		}
	},
	mounted() {
		this.fetch();
	},
	methods: {
		itemButtons(item) {
			return (item.buttons ?? []).map(button => {
				if (!button.endpoint) {
					return button;
				}

				const { endpoint, successMessage, ...props } = button;
				const isRequesting = this.requestingEndpoint !== null;

				return {
					...props,
					disabled: props.disabled || isRequesting,
					icon: this.requestingEndpoint === endpoint ? "loader" : props.icon,
					click: () => this.request(endpoint, successMessage)
				};
			});
		},
		async fetch() {
			this.isLoading = true;
			this.issue = null;

			try {
				const response = await this.load();
				this.sections = response.sections;
				this.summary = response.summary;
			} catch (error) {
				this.issue = error;
			} finally {
				this.isLoading = false;
			}
		},
		async request(endpoint, successMessage) {
			if (this.requestingEndpoint !== null) {
				return;
			}

			try {
				this.requestingEndpoint = endpoint;
				await this.$api.post(endpoint);
				await this.fetch();
				this.$panel.notification.success(successMessage);
			} catch (error) {
				this.$panel.error(error);
			} finally {
				this.requestingEndpoint = null;
			}
		}
	}
};
</script>

<template>
	<div v-if="isLoading === false" class="k-stripe-checkout-diagnostics-section">
		<k-section v-if="issue" :headline="$t('error')">
			<k-box :text="issue.message" :html="false" icon="alert" theme="negative" />
		</k-section>

		<template v-else>
			<k-section :headline="summary.headline">
				<!-- Kirby's System view uses this class to color themed stat labels. -->
				<k-stats class="k-system-info" :reports="summary.reports" size="medium" />
			</k-section>

			<k-section
				v-for="section in displaySections"
				:key="section.name"
				:buttons="section.action ? [section.action] : []"
				:headline="section.headline"
			>
				<k-items :items="section.items" />
			</k-section>
		</template>
	</div>
</template>
