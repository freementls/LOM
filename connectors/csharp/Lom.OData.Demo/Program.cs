// SPDX-License-Identifier: Apache-2.0
// Usage: Lom.OData.Demo <base-url> <api-key>
using Lom.OData;

if(args.Length < 2)
{
	Console.Error.WriteLine("usage: Lom.OData.Demo <base-url> <api-key>");
	Console.Error.WriteLine("example: Lom.OData.Demo http://127.0.0.1:8080 dev");
	return 1;
}
using var client = new LomODataClient(args[0], args[1]);
Console.WriteLine(await client.HealthAsync());
var people = await client.ListPeopleAsync(top: 5);
Console.WriteLine(people.Length > 400 ? people.Substring(0, 400) + "…" : people);
return 0;
