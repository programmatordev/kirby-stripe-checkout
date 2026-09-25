import { readFile } from "node:fs/promises";

// Loads plain component options for logic tests only. This does not mount Vue
// or exercise Kirby's rendering, lifecycle, or watcher scheduling.
export async function componentOptions(name) {
	const url = new URL(`../../src/components/${name}.vue`, import.meta.url);
	const source = await readFile(url, "utf8");
	const script = source.split("<script>")[1].split("</script>")[0]
		.replace(/from "(\.\.\/[^\"]+)"/g, (_, path) => `from "${new URL(path, url).href}"`);
	return (await import(`data:text/javascript;base64,${Buffer.from(script).toString("base64")}`)).default;
}
