// SPDX-License-Identifier: Apache-2.0
package lom;

import com.sun.jna.Library;
import com.sun.jna.Native;
import com.sun.jna.Pointer;
import com.sun.jna.Structure;
import com.sun.jna.ptr.LongByReference;

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
		int lom_doc_status(Pointer doc);
		String lom_doc_error(Pointer doc);
		long lom_doc_open_count(Pointer doc); /* size_t */
		int lom_doc_get(Pointer doc, String selectorUtf8, MatchList.ByReference list);
		int lom_doc_count(Pointer doc, String selectorUtf8, LongByReference outCount);
		int lom_doc_get_ois(Pointer doc, String selectorUtf8, OiList.ByReference list);
		int lom_doc_oi_match(Pointer doc, int oi, Match.ByReference out);
		int lom_doc_set_inner_text(Pointer doc, String selectorUtf8, String textUtf8);
		int lom_doc_new_before_close(Pointer doc, String parentSel, String fragment);
		int lom_doc_delete(Pointer doc, String selectorUtf8);
		int lom_doc_save_file(Pointer doc, String pathUtf8);
		int lom_doc_wal_persist(Pointer doc);
		void lom_match_list_init(MatchList.ByReference list);
		void lom_match_list_free(MatchList.ByReference list);
		void lom_oi_list_init(OiList.ByReference list);
		void lom_oi_list_free(OiList.ByReference list);
		String lom_version();
	}

	@Structure.FieldOrder({"items", "count", "cap"})
	public static class MatchList extends Structure {
		public Pointer items;
		public long count; /* size_t */
		public long cap;

		public static class ByReference extends MatchList implements Structure.ByReference {}
	}

	@Structure.FieldOrder({"offset", "endOff"})
	public static class Match extends Structure {
		public long offset;
		public long endOff;

		public static class ByReference extends Match implements Structure.ByReference {}
	}

	@Structure.FieldOrder({"items", "count", "cap"})
	public static class OiList extends Structure {
		public Pointer items;
		public long count; /* size_t */
		public long cap;

		public static class ByReference extends OiList implements Structure.ByReference {}
	}

	private Pointer doc;

	public LomNative(String path) {
		doc = Lib.INSTANCE.lom_doc_create_file(path);
		if(doc == null || Pointer.nativeValue(doc) == 0) {
			throw new IllegalStateException("lom_doc_create_file failed");
		}
		if(Lib.INSTANCE.lom_doc_status(doc) != 0) {
			String err = Lib.INSTANCE.lom_doc_error(doc);
			close();
			throw new IllegalStateException("lom_doc_create_file: " + err);
		}
	}

	public static String version() {
		return Lib.INSTANCE.lom_version();
	}

	public String error() {
		return Lib.INSTANCE.lom_doc_error(doc);
	}

	public long openCount() {
		return Lib.INSTANCE.lom_doc_open_count(doc);
	}

	/** Returns (offset, endOff) pairs for the selector. */
	public List<long[]> get(String selector) {
		MatchList.ByReference list = new MatchList.ByReference();
		Lib.INSTANCE.lom_match_list_init(list);
		int st = Lib.INSTANCE.lom_doc_get(doc, selector, list);
		if(st != 0) {
			Lib.INSTANCE.lom_match_list_free(list);
			throw new IllegalStateException("get failed: " + st + " " + error());
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

	/** Open indices (uint32) — internal currency; no offset pairs. */
	public List<Integer> getOis(String selector) {
		OiList.ByReference list = new OiList.ByReference();
		Lib.INSTANCE.lom_oi_list_init(list);
		int st = Lib.INSTANCE.lom_doc_get_ois(doc, selector, list);
		if(st != 0) {
			Lib.INSTANCE.lom_oi_list_free(list);
			throw new IllegalStateException("get_ois failed: " + st + " " + error());
		}
		int n = (int)list.count;
		List<Integer> out = new ArrayList<>(n);
		if(n > 0 && list.items != null) {
			for(int i = 0; i < n; i++)
				out.add(list.items.getInt((long)i * 4L));
		}
		Lib.INSTANCE.lom_oi_list_free(list);
		return out;
	}

	/** Materialize one open index to (offset, endOff). */
	public long[] oiMatch(int oi) {
		Match.ByReference m = new Match.ByReference();
		int st = Lib.INSTANCE.lom_doc_oi_match(doc, oi, m);
		if(st != 0)
			throw new IllegalStateException("oi_match failed: " + st + " " + error());
		return new long[] { m.offset, m.endOff };
	}

	public long count(String selector) {
		LongByReference n = new LongByReference();
		int st = Lib.INSTANCE.lom_doc_count(doc, selector, n);
		if(st != 0) {
			throw new IllegalStateException("count failed: " + st + " " + error());
		}
		return n.getValue();
	}

	public void set(String selector, String text) {
		int st = Lib.INSTANCE.lom_doc_set_inner_text(doc, selector, text);
		if(st != 0) throw new IllegalStateException("set failed: " + st + " " + error());
	}

	public void newBeforeClose(String parentSelector, String fragment) {
		int st = Lib.INSTANCE.lom_doc_new_before_close(doc, parentSelector, fragment);
		if(st != 0) throw new IllegalStateException("new failed: " + st + " " + error());
	}

	public void delete(String selector) {
		int st = Lib.INSTANCE.lom_doc_delete(doc, selector);
		if(st != 0) throw new IllegalStateException("delete failed: " + st + " " + error());
	}

	public void save(String path) {
		int st = Lib.INSTANCE.lom_doc_save_file(doc, path);
		if(st != 0) throw new IllegalStateException("save failed: " + st + " " + error());
	}

	public void walPersist() {
		int st = Lib.INSTANCE.lom_doc_wal_persist(doc);
		if(st != 0) throw new IllegalStateException("wal_persist failed: " + st + " " + error());
	}

	@Override
	public void close() {
		if(doc != null) {
			Lib.INSTANCE.lom_doc_free(doc);
			doc = null;
		}
	}
}
