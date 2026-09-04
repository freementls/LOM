// SPDX-License-Identifier: Apache-2.0
// Usage: dotnet run --project Lom.Native.Demo -- ../../test.xml person
// Requires: make -C native && LD_LIBRARY_PATH=../../native/lib
using Lom.Native;

if(args.Length < 1)
{
	Console.Error.WriteLine("usage: Lom.Native.Demo <xml-path> [selector]");
	return 1;
}
string path = args[0];
string sel = args.Length > 1 ? args[1] : "person";
Console.WriteLine("liblom " + LomDoc.Version);
using var doc = new LomDoc(path);
var hits = doc.Get(sel);
Console.WriteLine($"selector={sel} matches={hits.Count}");
int show = Math.Min(hits.Count, 5);
for(int i = 0; i < show; i++)
{
	var h = hits[i];
	string preview = h.Text.Length > 80 ? h.Text.Substring(0, 80) + "…" : h.Text;
	Console.WriteLine($"  [{i}] off={h.Offset} {preview}");
}
return 0;
