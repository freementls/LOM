// SPDX-License-Identifier: Apache-2.0
/** Thin OpenAPI/HTTP client for lomd. */
export type LomODataOptions = { baseUrl: string; apiKey: string };

export class LomODataClient {
  constructor(private opts: LomODataOptions) {}

  private async get(path: string): Promise<string> {
    const url = this.opts.baseUrl.replace(/\/$/, "") + "/" + path.replace(/^\//, "");
    const res = await fetch(url, { headers: { "X-Api-Key": this.opts.apiKey } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.text();
  }

  health(): Promise<string> {
    return this.get("health");
  }

  listPeople(filter?: string, top = 50): Promise<string> {
    let q = `api/ListPeople?$top=${top}`;
    if (filter) q += `&$filter=${encodeURIComponent(filter)}`;
    return this.get(q);
  }

  private async send(method: string, path: string, body?: string): Promise<string> {
    const url = this.opts.baseUrl.replace(/\/$/, "") + "/" + path.replace(/^\//, "");
    const res = await fetch(url, {
      method,
      headers: { "X-Api-Key": this.opts.apiKey, "Content-Type": "application/json" },
      body,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.text();
  }

  query(opts: { selector?: string; xpath?: string; css?: string; file?: string }): Promise<string> {
    return this.send("POST", "lom/query", JSON.stringify(opts));
  }

  createPerson(fields: Record<string, string>): Promise<string> {
    return this.send("POST", "api/CreatePeople", JSON.stringify(fields));
  }

  updatePerson(id: string, fields: Record<string, string>): Promise<string> {
    return this.send("PATCH", `odata/People('${id}')`, JSON.stringify(fields));
  }

  deletePerson(id: string): Promise<string> {
    return this.send("DELETE", `odata/People('${id}')`);
  }
}
