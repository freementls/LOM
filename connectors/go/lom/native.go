// SPDX-License-Identifier: Apache-2.0
package lom

/*
#cgo CFLAGS: -I${SRCDIR}/../../native/include
#cgo LDFLAGS: -L${SRCDIR}/../../native/lib -llom -Wl,-rpath,${SRCDIR}/../../native/lib
#include "lom.h"
#include <stdlib.h>
*/
import "C"
import (
	"errors"
	"unsafe"
)

// Doc is an in-process liblom document.
type Doc struct {
	ptr *C.lom_doc
}

// OpenFile mmap/loads path via lom_doc_create_file.
func OpenFile(path string) (*Doc, error) {
	cs := C.CString(path)
	defer C.free(unsafe.Pointer(cs))
	p := C.lom_doc_create_file(cs)
	if p == nil {
		return nil, errors.New("lom_doc_create_file failed")
	}
	return &Doc{ptr: p}, nil
}

func Version() string {
	return C.GoString(C.lom_version())
}

// Match is a byte span in the document buffer.
type Match struct {
	Offset int64
	EndOff int64
}

// Get runs a native subset selector (tag chains / @attr / =text).
func (d *Doc) Get(selector string) ([]Match, error) {
	if d == nil || d.ptr == nil {
		return nil, errors.New("nil doc")
	}
	cs := C.CString(selector)
	defer C.free(unsafe.Pointer(cs))
	var list C.lom_match_list
	C.lom_match_list_init(&list)
	st := C.lom_doc_get(d.ptr, cs, &list)
	if st != C.LOM_OK {
		C.lom_match_list_free(&list)
		return nil, errors.New("lom_doc_get failed")
	}
	n := int(list.count)
	out := make([]Match, n)
	if n > 0 && list.items != nil {
		items := unsafe.Slice(list.items, n)
		for i := 0; i < n; i++ {
			out[i] = Match{Offset: int64(items[i].offset), EndOff: int64(items[i].end_off)}
		}
	}
	C.lom_match_list_free(&list)
	return out, nil
}

func (d *Doc) Close() {
	if d != nil && d.ptr != nil {
		C.lom_doc_free(d.ptr)
		d.ptr = nil
	}
}
