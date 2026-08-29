<script>
/**
 * Reuses Kirby's Structure table while limiting translated variant values to
 * label edits. Kirby has no membership-locked mode for editable nested fields.
 */
export default {
	extends: "k-structure-field",
	computed: {
		options() {
			return this.disabled
				? []
				: [{
					click: "edit",
					icon: "edit",
					text: this.$t("edit")
				}];
		}
	},
	methods: {
		open(item, field, replace = false) {
			const index = this.findIndex(item);

			if (index === -1) {
				return false;
			}

			this.stopSelecting();
			this.$panel.drawer.open({
				component: "k-stripe-checkout-variant-drawer",
				id: this.id,
				on: {
					input: value => {
						const current = this.findIndex(item);

						if (current === -1) {
							return;
						}

						this.$set(this.items, current, value);
						this.save();
					},
					next: () => this.navigate(item, 1),
					prev: () => this.navigate(item, -1)
				},
				props: {
					disabled: this.disabled,
					icon: this.icon ?? "list-bullet",
					// Our shared drawer expects explicit booleans so navigation reaches
					// a disabled state at the first and last translated values.
					next: this.items[index + 1] !== undefined,
					prev: this.items[index - 1] !== undefined,
					tabs: {
						content: {
							fields: this.form(field)
						}
					},
					title: this.label,
					value: item
				},
				replace
			});
		}
	}
};
</script>

<style>
.k-field-type-stripe-checkout-variant-translation-values > .k-field-header > .k-button-group {
	display: none;
}
</style>
