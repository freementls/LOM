// SPDX-License-Identifier: Apache-2.0
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;

namespace Lom.Native
{
	[StructLayout(LayoutKind.Sequential)]
	struct LomMatch
	{
		public long Offset;
		public long EndOff;
	}

	[StructLayout(LayoutKind.Sequential)]
	struct LomMatchList
	{
		public IntPtr Items;
		public UIntPtr Count;
		public UIntPtr Cap;
	}

	/// <summary>P/Invoke wrapper around liblom (in-process document engine).</summary>
	public sealed class LomDoc : IDisposable
	{
		const string Lib = "lom"; // liblom.so / lom.dll — set LD_LIBRARY_PATH

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern IntPtr lom_doc_create_file(byte[] pathUtf8);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern void lom_doc_free(IntPtr doc);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_get(IntPtr doc, byte[] selectorUtf8, IntPtr matchList);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_node_slice(IntPtr doc, long openOff, out IntPtr ptr, out UIntPtr len);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_set_inner_text(IntPtr doc, byte[] selectorUtf8, byte[] textUtf8);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_new_before_close(IntPtr doc, byte[] parentSel, byte[] fragment);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_delete(IntPtr doc, byte[] selectorUtf8);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern int lom_doc_save_file(IntPtr doc, byte[] pathUtf8);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern void lom_match_list_init(IntPtr list);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern void lom_match_list_free(IntPtr list);

		[DllImport(Lib, CallingConvention = CallingConvention.Cdecl)]
		static extern IntPtr lom_version();

		readonly IntPtr _doc;
		bool _disposed;

		static byte[] Z(string s) => Encoding.UTF8.GetBytes(s + "\0");

		public static string Version
		{
			get
			{
				var p = lom_version();
				return p == IntPtr.Zero ? "" : Marshal.PtrToStringUTF8(p) ?? "";
			}
		}

		public LomDoc(string path)
		{
			_doc = lom_doc_create_file(Z(path));
			if(_doc == IntPtr.Zero)
				throw new InvalidOperationException("lom_doc_create_file failed");
		}

		/// <summary>Run a LOM selector; returns (offset, end, node XML slice) for each hit.</summary>
		public List<(long Offset, long End, string Text)> Get(string selector)
		{
			IntPtr listPtr = Marshal.AllocHGlobal(Marshal.SizeOf<LomMatchList>());
			try
			{
				Marshal.StructureToPtr(new LomMatchList(), listPtr, false);
				lom_match_list_init(listPtr);
				int st = lom_doc_get(_doc, Z(selector), listPtr);
				if(st != 0)
					throw new InvalidOperationException("get failed: " + st);
				var ml = Marshal.PtrToStructure<LomMatchList>(listPtr);
				int n = (int)ml.Count;
				var results = new List<(long, long, string)>(n);
				int stride = Marshal.SizeOf<LomMatch>();
				for(int i = 0; i < n; i++)
				{
					var m = Marshal.PtrToStructure<LomMatch>(ml.Items + i * stride);
					string text = "";
					if(lom_doc_node_slice(_doc, m.Offset, out IntPtr ptr, out UIntPtr len) == 0 && ptr != IntPtr.Zero)
						text = Marshal.PtrToStringUTF8(ptr, (int)len) ?? "";
					results.Add((m.Offset, m.EndOff, text));
				}
				return results;
			}
			finally
			{
				lom_match_list_free(listPtr);
				Marshal.FreeHGlobal(listPtr);
			}
		}

		public void Set(string selector, string text)
		{
			var st = lom_doc_set_inner_text(_doc, Z(selector), Z(text));
			if(st != 0) throw new InvalidOperationException("set failed: " + st);
		}

		public void New(string parentSelector, string fragment)
		{
			var st = lom_doc_new_before_close(_doc, Z(parentSelector), Z(fragment));
			if(st != 0) throw new InvalidOperationException("new failed: " + st);
		}

		public void Delete(string selector)
		{
			var st = lom_doc_delete(_doc, Z(selector));
			if(st != 0) throw new InvalidOperationException("delete failed: " + st);
		}

		public void Save(string path)
		{
			var st = lom_doc_save_file(_doc, Z(path));
			if(st != 0) throw new InvalidOperationException("save failed: " + st);
		}

		public void Dispose()
		{
			if(!_disposed && _doc != IntPtr.Zero)
			{
				lom_doc_free(_doc);
				_disposed = true;
			}
		}
	}
}
