import assert from "node:assert/strict";
import test from "node:test";

import { resolveStableId, stableId } from "../src/synchronized-structure.js";

test("generates compact stable identifiers without collisions", () => {
	const ids = Array.from({ length: 100 }, stableId);

	assert.ok(ids.every(id => /^[a-z0-9]{16}$/.test(id)));
	assert.equal(new Set(ids).size, ids.length);
});

test("maps temporary Structure identifiers to stable plugin identifiers", () => {
	const ids = new Map([["existing", "existing"]]);
	let generated = 0;
	const createId = () => `generated-${++generated}`;

	assert.equal(resolveStableId("existing", ids, createId), "existing");
	assert.equal(resolveStableId("temporary", ids, createId), "generated-1");
	assert.equal(resolveStableId("temporary", ids, createId), "generated-1");
	assert.equal(generated, 1);
});
