// SPDX-License-Identifier: Apache-2.0
using System;
using System.Net.Http;
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

		public void Dispose() => _http.Dispose();
	}
}
