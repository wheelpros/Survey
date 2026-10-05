// Every tool's output schema against a real api/v1 reply.
//
// fixtures/api-v1.json is written by `php tests/api-v1/run.php --fixtures`
// (repo root), which serves the real PHP endpoints over seeded data. A reply
// must parse - and parse to itself: a field PHP sends that the schema doesn't
// declare would be silently dropped before the model saw it, so that fails
// here too. Regenerate the fixtures whenever an endpoint's reply changes.

import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { z } from "zod";

import { READ_TOOLS } from "../src/portal/tools.js";
import { whoami } from "../src/portal/schemas.js";

const fixtures = JSON.parse(readFileSync(new URL("./fixtures/api-v1.json", import.meta.url), "utf8"));

const outputs = [["whoami", whoami], ...READ_TOOLS.map((t) => [t.name, t.output])];

describe("api/v1 contract", () => {
  it("has a real reply for every tool", () => {
    expect(Object.keys(fixtures).sort()).toEqual(outputs.map(([name]) => name).sort());
  });

  it.each(outputs)("%s matches its output schema exactly", (name, shape) => {
    const parsed = z.object(shape).safeParse(fixtures[name]);
    expect(parsed.success, JSON.stringify(parsed.error?.issues)).toBe(true);
    expect(parsed.data).toEqual(fixtures[name]);
  });

  it("puts what people typed under untrusted_content", () => {
    expect(fixtures.get_form_response.untrusted_content.answers[0].answer).toMatch(/admin mode/);
    expect(fixtures.get_client_overview.client.untrusted_content.description).toMatch(/Ignore all previous/);
    expect(fixtures.get_client_overview.client).not.toHaveProperty("description");
  });
});
