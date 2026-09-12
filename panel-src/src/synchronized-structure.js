export function stableId() {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
	let id = "";

	while (id.length < 16) {
		const bytes = new Uint8Array(16);
		globalThis.crypto.getRandomValues(bytes);

		for (const byte of bytes) {
			// 252 is the largest multiple of 36 below 256, avoiding modulo bias.
			if (byte < 252) {
				id += alphabet[byte % alphabet.length];
			}

			if (id.length === 16) {
				break;
			}
		}
	}

	return id;
}

export function resolveStableId(temporaryId, ids, createId = stableId) {
	if (typeof temporaryId === "string" && temporaryId !== "") {
		if (ids.has(temporaryId) === false) {
			ids.set(temporaryId, createId());
		}

		return ids.get(temporaryId);
	}

	return createId();
}
