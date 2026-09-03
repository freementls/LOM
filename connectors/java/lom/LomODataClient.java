// SPDX-License-Identifier: Apache-2.0
package lom;

import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;

/** Thin HTTP client for lomd (OpenAPI / OData). */
public final class LomODataClient implements AutoCloseable {
	private final HttpClient http = HttpClient.newHttpClient();
	private final String base;
	private final String apiKey;

	public LomODataClient(String baseUrl, String apiKey) {
		this.base = baseUrl.replaceAll("/$", "");
		this.apiKey = apiKey;
	}

	private String get(String path) throws Exception {
		HttpRequest req = HttpRequest.newBuilder(URI.create(base + "/" + path))
			.header("X-Api-Key", apiKey)
			.GET()
			.build();
		HttpResponse<String> res = http.send(req, HttpResponse.BodyHandlers.ofString());
		if(res.statusCode() >= 300) {
			throw new IllegalStateException("HTTP " + res.statusCode() + ": " + res.body());
		}
		return res.body();
	}

	public String health() throws Exception {
		return get("health");
	}

	public String listPeople(String filter, int top) throws Exception {
		String q = "api/ListPeople?$top=" + top;
		if(filter != null && !filter.isEmpty()) {
			q += "&$filter=" + URLEncoder.encode(filter, StandardCharsets.UTF_8);
		}
		return get(q);
	}

	@Override
	public void close() {}
}
