// SPDX-License-Identifier: Apache-2.0
package lom;

import com.sun.jna.Library;
import com.sun.jna.Native;
import com.sun.jna.Pointer;
import com.sun.jna.Structure;

import java.util.ArrayList;
import java.util.List;

/**
 * In-process binding to {@code liblom} via JNA.
 * <p>
 * Dependency: {@code net.java.dev.jna:jna:5.14.0} (or newer).
 * Set {@code jna.library.path} to {@code native/lib} (or install {@code liblom.so} on the loader path).
 */
public final class LomNative implements AutoCloseable {
	public interface Lib extends Library {
		Lib INSTANCE = Native.load("lom", Lib.class);

		Pointer lom_doc_create_file(String pathUtf8);
		void lom_doc_free(Pointer doc);
		int lom_doc_get(Pointer doc, String selectorUtf8, MatchList.ByReference list);
		void lom_match_list_init(MatchList.ByReference list);
		void lom_match_list_free(MatchList.ByReference list);
		String lom_version();
	}

	@Structure.FieldOrder({"items", "count", "cap"})
	public static class MatchList extends Structure {
		public Pointer items;
		public long count; /* size_t */
		public long cap;

		public static class ByReference extends MatchList implements Structure.ByReference {}
	}

	private Pointer doc;

	public LomNative(String path) {
		doc = Lib.INSTANCE.lom_doc_create_file(path);
		if(doc == null || Pointer.nativeValue(doc) == 0) {
			throw new IllegalStateException("lom_doc_create_file failed");
		}
	}

	public static String version() {
		return Lib.INSTANCE.lom_version();
	}

	/** Returns (offset, endOff) pairs for the selector. */
	public List<long[]> get(String selector) {
		MatchList.ByReference list = new MatchList.ByReference();
		Lib.INSTANCE.lom_match_list_init(list);
		int st = Lib.INSTANCE.lom_doc_get(doc, selector, list);
		if(st != 0) {
			Lib.INSTANCE.lom_match_list_free(list);
			throw new IllegalStateException("get failed: " + st);
		}
		int n = (int)list.count;
		List<long[]> out = new ArrayList<>(n);
		if(n > 0 && list.items != null) {
			for(int i = 0; i < n; i++) {
				Pointer p = list.items.share((long)i * 16L);
				out.add(new long[] { p.getLong(0), p.getLong(8) });
			}
		}
		Lib.INSTANCE.lom_match_list_free(list);
		return out;
	}

	@Override
	public void close() {
		if(doc != null) {
			Lib.INSTANCE.lom_doc_free(doc);
			doc = null;
		}
	}
}
