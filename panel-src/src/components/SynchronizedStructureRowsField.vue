<script>
/**
 * Keeps a nested Structure list fixed while allowing its translated fields to
 * use Kirby's native editor and drawer behavior.
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
				component: "k-stripe-checkout-synchronized-structure-drawer",
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
.k-field-type-stripe-checkout-synchronized-structure-rows > .k-field-header > .k-button-group {
	display: none;
}
</style>
