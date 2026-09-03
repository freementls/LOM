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
}
