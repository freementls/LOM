# SPDX-License-Identifier: Apache-2.0
"""Thin LOM connectors: ctypes to liblom, and HTTP to lomd."""
from __future__ import annotations

import ctypes
import os
import urllib.parse
import urllib.request
from typing import Optional


def _lib_path() -> str:
	env = os.environ.get("LOM_LIB")
	if env:
		return env
	here = os.path.dirname(os.path.abspath(__file__))
	return os.path.normpath(os.path.join(here, "..", "..", "native", "lib", "liblom.so"))


class LomNative:
	def __init__(self, path: str, lib: Optional[str] = None):
		self._lib = ctypes.CDLL(lib or _lib_path())
		self._lib.lom_doc_create_file.restype = ctypes.c_void_p
		self._lib.lom_doc_create_file.argtypes = [ctypes.c_char_p]
		self._lib.lom_doc_free.argtypes = [ctypes.c_void_p]
		self._lib.lom_doc_set_inner_text.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_char_p]
		self._lib.lom_doc_save_file.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
		self._doc = self._lib.lom_doc_create_file(path.encode())
		if not self._doc:
			raise RuntimeError("lom_doc_create_file failed")

	def get(self, selector: str):
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
			raise RuntimeError(f"get failed: {st}")
		out = [(ml.items[i].offset, ml.items[i].end_off) for i in range(ml.count)]
		self._lib.lom_match_list_free(ctypes.byref(ml))
		return out

	def set(self, selector: str, text: str) -> None:
		st = self._lib.lom_doc_set_inner_text(self._doc, selector.encode(), text.encode())
		if st != 0:
			raise RuntimeError(f"set failed: {st}")

	def save(self, path: str) -> None:
		st = self._lib.lom_doc_save_file(self._doc, path.encode())
		if st != 0:
			raise RuntimeError(f"save failed: {st}")

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
