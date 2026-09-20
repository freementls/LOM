# SPDX-License-Identifier: Apache-2.0
"""Thin LOM connectors: ctypes to liblom, and HTTP to lomd."""
from __future__ import annotations

import ctypes
import os
import urllib.parse
import urllib.request
from typing import List, Optional, Tuple


def _lib_path() -> str:
	env = os.environ.get("LOM_LIB")
	if env:
		return env
	here = os.path.dirname(os.path.abspath(__file__))
	return os.path.normpath(os.path.join(here, "..", "..", "native", "lib", "liblom.so"))


class LomNative:
	"""In-process liblom document (get / count / set / new_ / delete / save)."""

	def __init__(self, path: str, lib: Optional[str] = None):
		self._lib = ctypes.CDLL(lib or _lib_path())
		self._lib.lom_version.restype = ctypes.c_char_p
		self._lib.lom_doc_create_file.restype = ctypes.c_void_p
		self._lib.lom_doc_create_file.argtypes = [ctypes.c_char_p]
		self._lib.lom_doc_free.argtypes = [ctypes.c_void_p]
		self._lib.lom_doc_error.restype = ctypes.c_char_p
		self._lib.lom_doc_error.argtypes = [ctypes.c_void_p]
		self._lib.lom_doc_status.argtypes = [ctypes.c_void_p]
		self._lib.lom_doc_open_count.restype = ctypes.c_size_t
		self._lib.lom_doc_open_count.argtypes = [ctypes.c_void_p]
		self._lib.lom_doc_set_inner_text.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_char_p]
		self._lib.lom_doc_new_before_close.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_char_p]
		self._lib.lom_doc_delete.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
		self._lib.lom_doc_save_file.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
		self._lib.lom_doc_count.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.POINTER(ctypes.c_size_t)]
		self._doc = self._lib.lom_doc_create_file(path.encode())
		if not self._doc:
			raise RuntimeError("lom_doc_create_file failed")
		if self._lib.lom_doc_status(self._doc) != 0:
			err = self.error
			self.close()
			raise RuntimeError(f"lom_doc_create_file: {err}")

	@staticmethod
	def version(lib: Optional[str] = None) -> str:
		l = ctypes.CDLL(lib or _lib_path())
		l.lom_version.restype = ctypes.c_char_p
		v = l.lom_version()
		return v.decode() if v else ""

	@property
	def error(self) -> str:
		e = self._lib.lom_doc_error(self._doc)
		return e.decode() if e else ""

	@property
	def open_count(self) -> int:
		return int(self._lib.lom_doc_open_count(self._doc))

	def get(self, selector: str) -> List[Tuple[int, int]]:
		"""Return list of (offset, end_off) from lom_doc_get."""
		class Match(ctypes.Structure):
			_fields_ = [("offset", ctypes.c_int64), ("end_off", ctypes.c_int64)]

		class MatchList(ctypes.Structure):
			_fields_ = [("items", ctypes.POINTER(Match)), ("count", ctypes.c_size_t), ("cap", ctypes.c_size_t)]

		self._lib.lom_match_list_init.argtypes = [ctypes.POINTER(MatchList)]
		self._lib.lom_match_list_free.argtypes = [ctypes.POINTER(MatchList)]
		self._lib.lom_doc_get.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.POINTER(MatchList)]
		ml = MatchList()
		self._lib.lom_match_list_init(ctypes.byref(ml))
		st = self._lib.lom_doc_get(self._doc, selector.encode(), ctypes.byref(ml))
		if st != 0:
			self._lib.lom_match_list_free(ctypes.byref(ml))
			raise RuntimeError(f"get failed: {st} {self.error}")
		out = [(ml.items[i].offset, ml.items[i].end_off) for i in range(ml.count)]
		self._lib.lom_match_list_free(ctypes.byref(ml))
		return out

	def get_ois(self, selector: str) -> List[int]:
		"""Return open indices (uint32) — internal currency; no offset pairs."""
		class OiList(ctypes.Structure):
			_fields_ = [
				("items", ctypes.POINTER(ctypes.c_uint32)),
				("count", ctypes.c_size_t),
				("cap", ctypes.c_size_t),
			]

		self._lib.lom_oi_list_init.argtypes = [ctypes.POINTER(OiList)]
		self._lib.lom_oi_list_free.argtypes = [ctypes.POINTER(OiList)]
		self._lib.lom_doc_get_ois.argtypes = [
			ctypes.c_void_p,
			ctypes.c_char_p,
			ctypes.POINTER(OiList),
		]
		ol = OiList()
		self._lib.lom_oi_list_init(ctypes.byref(ol))
		st = self._lib.lom_doc_get_ois(self._doc, selector.encode(), ctypes.byref(ol))
		if st != 0:
			self._lib.lom_oi_list_free(ctypes.byref(ol))
			raise RuntimeError(f"get_ois failed: {st} {self.error}")
		out = [int(ol.items[i]) for i in range(ol.count)]
		self._lib.lom_oi_list_free(ctypes.byref(ol))
		return out

	def oi_match(self, oi: int) -> Tuple[int, int]:
		"""Materialize one open index to (offset, end_off)."""
		class Match(ctypes.Structure):
			_fields_ = [("offset", ctypes.c_int64), ("end_off", ctypes.c_int64)]

		self._lib.lom_doc_oi_match.argtypes = [
			ctypes.c_void_p,
			ctypes.c_uint32,
			ctypes.POINTER(Match),
		]
		m = Match()
		st = self._lib.lom_doc_oi_match(self._doc, ctypes.c_uint32(oi), ctypes.byref(m))
		if st != 0:
			raise RuntimeError(f"oi_match failed: {st} {self.error}")
		return (int(m.offset), int(m.end_off))

	def count(self, selector: str) -> int:
		n = ctypes.c_size_t(0)
		st = self._lib.lom_doc_count(self._doc, selector.encode(), ctypes.byref(n))
		if st != 0:
			raise RuntimeError(f"count failed: {st} {self.error}")
		return int(n.value)

	def set(self, selector: str, text: str) -> None:
		st = self._lib.lom_doc_set_inner_text(self._doc, selector.encode(), text.encode())
		if st != 0:
			raise RuntimeError(f"set failed: {st} {self.error}")

	def new_(self, parent_selector: str, fragment: str) -> None:
		st = self._lib.lom_doc_new_before_close(
			self._doc, parent_selector.encode(), fragment.encode()
		)
		if st != 0:
			raise RuntimeError(f"new_ failed: {st} {self.error}")

	def delete(self, selector: str) -> None:
		st = self._lib.lom_doc_delete(self._doc, selector.encode())
		if st != 0:
			raise RuntimeError(f"delete failed: {st} {self.error}")

	def save(self, path: str) -> None:
		st = self._lib.lom_doc_save_file(self._doc, path.encode())
		if st != 0:
			raise RuntimeError(f"save failed: {st} {self.error}")

	def wal_persist(self) -> None:
		self._lib.lom_doc_wal_persist.argtypes = [ctypes.c_void_p]
		st = self._lib.lom_doc_wal_persist(self._doc)
		if st != 0:
			raise RuntimeError(f"wal_persist failed: {st} {self.error}")

	def get_xpath(self, xpath: str) -> List[Tuple[int, int]]:
		buf = ctypes.create_string_buffer(1024)
		self._lib.lom_xpath_to_lom.argtypes = [ctypes.c_char_p, ctypes.c_char_p, ctypes.c_size_t]
		st = self._lib.lom_xpath_to_lom(xpath.encode(), buf, 1024)
		if st != 0:
			raise RuntimeError(f"xpath not in subset: {xpath}")
		return self.get(buf.value.decode())

	def close(self) -> None:
		if self._doc:
			self._lib.lom_doc_free(self._doc)
			self._doc = None

	def __enter__(self):
		return self

	def __exit__(self, *args):
		self.close()


class LomOData:
	def __init__(self, base_url: str, api_key: str):
		self.base = base_url.rstrip("/")
		self.api_key = api_key

	def _get(self, path: str) -> str:
		req = urllib.request.Request(
			self.base + "/" + path.lstrip("/"),
			headers={"X-Api-Key": self.api_key},
		)
		with urllib.request.urlopen(req) as resp:
			return resp.read().decode()

	def health(self) -> str:
		return self._get("health")

	def list_people(self, filter: Optional[str] = None, top: int = 50) -> str:
		q = f"api/ListPeople?$top={top}"
		if filter:
			q += "&$filter=" + urllib.parse.quote(filter)
		return self._get(q)

	def _req(self, method: str, path: str, body: Optional[str] = None) -> str:
		req = urllib.request.Request(
			self.base + "/" + path.lstrip("/"),
			data=body.encode() if body is not None else None,
			method=method,
			headers={"X-Api-Key": self.api_key, "Content-Type": "application/json"},
		)
		with urllib.request.urlopen(req) as resp:
			return resp.read().decode()

	def query(self, selector: str = "", xpath: str = "", css: str = "", file: str = "") -> str:
		parts = []
		if selector:
			parts.append(f'"selector":"{selector}"')
		if xpath:
			parts.append(f'"xpath":"{xpath}"')
		if css:
			parts.append(f'"css":"{css}"')
		if file:
			parts.append(f'"file":"{file}"')
		return self._req("POST", "lom/query", "{" + ",".join(parts) + "}")

	def create_person(self, fields: dict) -> str:
		import json
		return self._req("POST", "api/CreatePeople", json.dumps(fields))

	def update_person(self, id: str, fields: dict) -> str:
		import json
		return self._req("PATCH", f"odata/People('{id}')", json.dumps(fields))

	def delete_person(self, id: str) -> str:
		return self._req("DELETE", f"odata/People('{id}')")
