// SPDX-License-Identifier: Apache-2.0
using System;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;

namespace Lom.OData
{
	/// <summary>HTTP client for lomd (OpenAPI / OData surface).</summary>
	public sealed class LomODataClient : IDisposable
	{
		readonly HttpClient _http;

		public LomODataClient(string baseUrl, string apiKey)
		{
			_http = new HttpClient { BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/") };
			_http.DefaultRequestHeaders.Add("X-Api-Key", apiKey);
		}

		public async Task<string> HealthAsync()
		{
			return await _http.GetStringAsync("health");
		}

		public async Task<string> ListPeopleAsync(string? filter = null, int top = 50)
		{
			var q = "api/ListPeople?$top=" + top;
			if(!string.IsNullOrEmpty(filter))
				q += "&$filter=" + Uri.EscapeDataString(filter);
			return await _http.GetStringAsync(q);
		}

		public async Task<string> ODataPeopleAsync(string? filter = null, int top = 50)
		{
			var q = "odata/People?$top=" + top;
			if(!string.IsNullOrEmpty(filter))
				q += "&$filter=" + Uri.EscapeDataString(filter);
			return await _http.GetStringAsync(q);
		}

		public async Task<string> QueryAsync(string? selector = null, string? xpath = null)
		{
			var body = "{";
			if(!string.IsNullOrEmpty(selector)) body += "\"selector\":\"" + selector + "\"";
			else if(!string.IsNullOrEmpty(xpath)) body += "\"xpath\":\"" + xpath + "\"";
			body += "}";
			var resp = await _http.PostAsync("lom/query", new StringContent(body, Encoding.UTF8, "application/json"));
			return await resp.Content.ReadAsStringAsync();
		}

		public async Task<string> CreatePersonAsync(string jsonBody)
		{
			var resp = await _http.PostAsync("api/CreatePeople", new StringContent(jsonBody, System.Text.Encoding.UTF8, "application/json"));
			return await resp.Content.ReadAsStringAsync();
		}

		public async Task<string> UpdatePersonAsync(string id, string jsonBody)
		{
			var req = new HttpRequestMessage(HttpMethod.Patch, "odata/People('" + id + "')")
			{
				Content = new StringContent(jsonBody, System.Text.Encoding.UTF8, "application/json")
			};
			var resp = await _http.SendAsync(req);
			return await resp.Content.ReadAsStringAsync();
		}

		public async Task DeletePersonAsync(string id)
		{
			var resp = await _http.DeleteAsync("odata/People('" + id + "')");
			resp.EnsureSuccessStatusCode();
		}

		public void Dispose() => _http.Dispose();
	}
}
